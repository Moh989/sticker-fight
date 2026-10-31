<?php

namespace Tests\Feature;

use App\Models\GuestSession;
use App\Models\Material;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NewdesShoppingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): Product
    {
        $this->seed();
        Material::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED TEST ONLY']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);
        StoreSettings::put('price_single_iqd', 8000);
        StoreSettings::put('accepting_orders', true);
        config(['sticker.track_cart_additions' => true]);
        $product = Product::firstOrFail();
        $product->update(['is_demo' => false]);

        return $product;
    }

    private function visitor(): GuestSession
    {
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', (string) Str::uuid()), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $guest->id]);

        return $guest;
    }

    private function orderOptions(array $changes = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1, 'front_template_id' => TemplateVersion::whereHas('template', fn ($q) => $q->where('kind', 'small_chip'))->value('id')], $changes);
    }

    public function test_only_the_eleventh_distinct_cart_qualifies_and_bestsellers_are_hidden_until_then(): void
    {
        $product = $this->fixture();
        for ($i = 0; $i < 10; $i++) {
            $this->visitor();
            $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions(['quantity' => 7]))->assertOk();
        }
        $this->assertSame(10, $product->fresh()->popularity_count);
        $this->get('/')->assertOk()->assertDontSee('data-bestsellers', false);

        $this->visitor();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();

        $this->assertSame(11, $product->fresh()->popularity_count);
        $this->get('/')->assertOk()->assertSee('data-bestsellers', false)->assertSee('الأكثر مبيعاً');
        $this->assertDatabaseCount('product_popularity_events', 11);
    }

    public function test_retries_quantity_changes_new_options_and_delete_readd_in_the_same_cart_count_once(): void
    {
        $product = $this->fixture();
        $this->visitor();
        $payload = $this->orderOptions();
        $response = $this->postJson('/api/products/'.$product->id.'/cart', $payload)->assertOk();
        $this->postJson('/api/products/'.$product->id.'/cart', $payload)->assertOk();
        $item = $response->json('item_id');
        $this->patch('/cart/'.$item, ['quantity' => 10])->assertRedirect('/cart');
        $this->delete('/cart/'.$item)->assertRedirect('/cart');
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();

        $this->assertSame(1, $product->fresh()->popularity_count);
        $this->assertDatabaseCount('product_popularity_events', 1);
    }

    #[TestWith(['admin'])]
    #[TestWith(['orders'])]
    #[TestWith(['production'])]
    #[TestWith(['shipping'])]
    public function test_staff_additions_are_excluded(string $role): void
    {
        $product = $this->fixture();
        $this->visitor();
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();

        $this->assertSame(0, $product->fresh()->popularity_count);
        $this->assertDatabaseCount('product_popularity_events', 0);
    }

    public function test_demo_products_disabled_metrics_and_unsuccessful_additions_are_not_counted(): void
    {
        $product = $this->fixture();
        $this->visitor();
        $product->update(['is_demo' => true]);
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();
        $product->update(['is_demo' => false]);
        config(['sticker.track_cart_additions' => false]);
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();
        config(['sticker.track_cart_additions' => true]);
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions(['quantity' => 0]))->assertUnprocessable();

        $this->assertSame(0, $product->fresh()->popularity_count);
        $this->assertDatabaseCount('product_popularity_events', 0);
    }

    public function test_checkout_rotates_the_cart_without_counting_an_order_as_an_addition(): void
    {
        Queue::fake();
        Storage::fake('local');
        $product = $this->fixture();
        $guest = $this->visitor();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions(['quantity' => 3]))->assertOk();
        $oldToken = $guest->fresh()->cart_token;

        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'Test Shopper', 'phone' => '07701234567', 'governorate_id' => 1, 'district' => 'Test district', 'address' => 'Test street 12', 'rights' => true, 'confirmed' => true])->assertOk();

        $this->assertSame(1, $product->fresh()->popularity_count);
        $this->assertNotSame($oldToken, $guest->fresh()->cart_token);
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();
        $this->assertSame(2, $product->fresh()->popularity_count);
        $this->assertSame(0, DB::table('product_popularity_events')->where('kind', 'order')->count());
    }

    public function test_catalog_respects_explicit_price_sort_and_filters_but_features_lead_the_default(): void
    {
        $featured = Product::create(['name' => 'Featured pine', 'slug' => 'pine', 'art_key' => 'minimal', 'price_iqd' => 9000, 'is_featured' => true, 'featured_order' => 2]);
        $first = Product::create(['name' => 'Featured stone', 'slug' => 'stone', 'art_key' => 'minimal', 'price_iqd' => 10000, 'is_featured' => true, 'featured_order' => 1]);
        $cheap = Product::create(['name' => 'Regular leaf', 'slug' => 'leaf', 'art_key' => 'bloom', 'price_iqd' => 2000]);

        $this->get('/designs')->assertOk()->assertViewHas('products', fn ($p) => $p->modelKeys() === [$first->id, $featured->id, $cheap->id]);
        $this->get('/designs?sort=price_asc')->assertOk()->assertViewHas('products', fn ($p) => $p->first()->is($cheap));
        $this->get('/designs?q=pine')->assertOk()->assertViewHas('products', fn ($p) => $p->modelKeys() === [$featured->id]);
        $this->get('/designs?max_price=3000')->assertOk()->assertViewHas('products', fn ($p) => $p->modelKeys() === [$cheap->id]);
        $this->get('/designs?min_price=12000&max_price=1000')->assertRedirect()->assertSessionHasErrors('max_price');
    }

    public function test_bestseller_ranking_uses_additions_instead_of_feature_priority(): void
    {
        Product::create(['name' => 'Featured', 'slug' => 'featured', 'art_key' => 'minimal', 'is_featured' => true, 'popularity_count' => 11]);
        $popular = Product::create(['name' => 'Popular', 'slug' => 'popular', 'art_key' => 'minimal', 'popularity_count' => 20]);

        $this->get('/')->assertOk()->assertViewHas('best', fn ($best) => $best->first()->is($popular));
        $this->get('/designs?bestsellers=1')->assertOk()->assertViewHas('products', fn ($p) => $p->first()->is($popular));
    }

    public function test_ready_checkout_and_real_sales_are_independent_from_cart_additions(): void
    {
        Queue::fake();
        Storage::fake('local');
        $product = $this->fixture();
        $this->visitor();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions(['quantity' => 3]))->assertOk();
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'Test Shopper', 'phone' => '07701234567', 'governorate_id' => 1, 'district' => 'Test district', 'address' => 'Test street', 'rights' => true, 'confirmed' => true])->assertOk();
        Order::firstOrFail()->update(['status' => 'delivered']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin')->assertOk()->assertViewHas('cartAdditions', 1)->assertViewHas('soldUnits', 3)->assertViewHas('sales', 29000);
        $this->get('/admin/orders/'.Order::first()->id)->assertOk()->assertSee('Test street');
    }

    public function test_english_province_names_resolve_to_the_same_server_shipping_rate(): void
    {
        $product = $this->fixture();
        $this->visitor();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();

        $this->withSession(['locale' => 'en'])->postJson('/api/checkout/quote', ['governorate' => 'Baghdad', 'district' => 'Test district'])->assertOk()->assertJsonPath('shipping_iqd', 5000)->assertJsonPath('total_iqd', 13000);
    }
}
