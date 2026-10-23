<?php

namespace Tests\Feature;

use App\Jobs\ExportOrderItem;
use App\Models\CardTemplate;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\GuestSession;
use App\Models\Material;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Services\ReadyProductService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductPopularityTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed();
        Queue::fake();
        Storage::fake('local');
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'popularity-test'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $guest->id]);
        StoreSettings::put('accepting_orders', true);
        StoreSettings::put('price_single_iqd', 7000);
        StoreSettings::put('price_double_iqd', 12000);
        Material::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);
        $this->product = Product::firstOrFail();
    }

    private function orderOptions(array $overrides = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1,
            'front_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'front'))->value('id'),
            'back_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'back'))->value('id'),
        ], ...$overrides];
    }

    private function add(array $options = []): array
    {
        $input = $this->orderOptions($options);
        $this->postJson('/api/products/'.$this->product->id.'/cart', $input)->assertOk();

        return $input;
    }

    private function checkout(): array
    {
        return ['request_id' => (string) Str::uuid(), 'name' => 'Popularity test customer', 'phone' => '07701234567', 'governorate_id' => 1, 'city' => 'Test', 'district' => 'Test', 'address' => 'Test address', 'landmark' => 'Test', 'rights' => true, 'confirmed' => true];
    }

    public function test_eleventh_cart_addition_promotes_the_design_and_shows_its_badge_everywhere(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->add(['quantity' => 100]);
        }
        $this->assertSame(10, $this->product->fresh()->popularity_count);
        $this->get('/')->assertOk()->assertViewHas('best', fn ($best) => $best->isEmpty())->assertDontSee('id="bestsellers"', false);
        $this->get('/designs')->assertOk()->assertDontSee('class="bestseller-badge"', false);
        $this->get('/products/'.$this->product->slug)->assertOk()->assertDontSee('class="bestseller-badge"', false);
        $this->add();
        $this->assertSame(11, $this->product->fresh()->popularity_count);
        $this->get('/')->assertOk()->assertViewHas('best', fn ($best) => $best->modelKeys() === [$this->product->id])->assertSee('id="bestsellers"', false)->assertSee('اكثر مبيعاً');
        $this->get('/designs')->assertOk()->assertSee('class="bestseller-badge"', false)->assertSee('اكثر مبيعاً');
        $this->get('/products/'.$this->product->slug)->assertOk()->assertSee('اكثر مبيعاً');
        $this->get('/designs?bestsellers=1')->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 1)->assertSee('اكثر مبيعاً');
        $this->assertSame(11, $this->product->fresh()->popularity_count);
    }

    public function test_cart_retries_quantity_changes_option_changes_and_views_do_not_inflate_the_count(): void
    {
        $input = $this->add();
        $item = CartItem::firstOrFail();
        $this->postJson('/api/products/'.$this->product->id.'/cart', $input)->assertOk();
        $this->postJson('/api/products/'.$this->product->id.'/quote', $input)->assertOk();
        $this->patch('/cart/'.$item->id, ['quantity' => 100])->assertRedirect('/cart');
        $this->postJson('/api/cart', ['design_id' => $input['request_id'], 'revision' => 1, 'quantity' => 2])->assertOk();
        $replacement = $this->add(['mode' => 'double', 'cart_item_id' => $item->id]);
        $this->postJson('/api/products/'.$this->product->id.'/cart', $replacement)->assertOk();
        $this->assertSame(1, CartItem::count());
        $this->assertSame(1, $this->product->fresh()->popularity_count);
        $this->assertDatabaseCount('product_popularity_events', 1);
    }

    public function test_removing_a_cart_item_preserves_history_and_replaying_its_add_does_not_count_again(): void
    {
        $input = $this->add();
        $this->delete('/cart/'.CartItem::firstOrFail()->id)->assertRedirect('/cart');
        $this->assertSame(1, $this->product->fresh()->popularity_count);
        $this->postJson('/api/products/'.$this->product->id.'/cart', $input)->assertOk();
        $this->assertSame(1, $this->product->fresh()->popularity_count);
        $this->delete('/cart/'.CartItem::firstOrFail()->id)->assertRedirect('/cart');
        $this->postJson('/api/cart', ['design_id' => $input['request_id'], 'revision' => 1, 'quantity' => 1])->assertOk();
        $this->assertSame(1, $this->product->fresh()->popularity_count);
        $this->assertDatabaseCount('product_popularity_events', 1);
    }

    public function test_cart_additions_and_order_requests_combine_with_one_order_event_per_product(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->add(['quantity' => 3]);
        }
        $first = $this->checkout();
        $this->postJson('/api/checkout', $first)->assertOk();
        $this->assertSame(5, OrderItem::count());
        $this->assertSame(0, CartItem::count());
        $this->assertSame(6, $this->product->fresh()->popularity_count);
        $this->postJson('/api/checkout', $first)->assertOk();
        $this->assertSame(6, $this->product->fresh()->popularity_count);
        for ($i = 0; $i < 4; $i++) {
            $this->add();
        }
        $this->assertSame(10, $this->product->fresh()->popularity_count);
        $this->get('/')->assertViewHas('best', fn ($best) => $best->isEmpty());
        $this->postJson('/api/checkout', $this->checkout())->assertOk();
        $this->assertSame(11, $this->product->fresh()->popularity_count);
        $this->assertSame(2, DB::table('product_popularity_events')->where('kind', 'order')->count());
        $this->assertSame(0, CartItem::count());
        $this->get('/')->assertViewHas('best', fn ($best) => $best->contains('id', $this->product->id));
        Queue::assertPushed(ExportOrderItem::class, 9);
    }

    public function test_rejected_additions_and_rolled_back_transactions_do_not_count(): void
    {
        TemplateVersion::query()->update(['approved_at' => null]);
        $this->postJson('/api/products/'.$this->product->id.'/cart', $this->orderOptions())->assertUnprocessable();
        $this->assertSame(0, $this->product->fresh()->popularity_count);
        TemplateVersion::query()->update(['approved_at' => now()]);
        try {
            DB::transaction(function () {
                app(ReadyProductService::class)->addToCart($this->product, $this->orderOptions());
                throw new \RuntimeException('Simulated transaction failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated transaction failure', $exception->getMessage());
        }
        $this->assertSame(0, CartItem::count());
        $this->assertSame(0, $this->product->fresh()->popularity_count);
        $this->assertDatabaseCount('product_popularity_events', 0);
    }

    public function test_private_custom_artwork_never_becomes_a_public_bestseller(): void
    {
        $ready = $this->add();
        $design = Design::findOrFail($ready['request_id']);
        $options = [...$design->options, 'product_id' => null];
        unset($options['ready_locked']);
        $document = $design->document;
        $document['sides']['front']['elements'] = [];
        $document['sides']['front']['background'] = '#FF2D8D';
        $id = (string) Str::uuid();
        $this->putJson('/api/designs/'.$id, ['name' => 'PRIVATE CUSTOMER ART', 'revision' => 0, 'document' => $document, 'options' => $options])->assertOk();
        $this->postJson('/api/cart', ['design_id' => $id, 'revision' => 1, 'quantity' => 100])->assertOk();
        $this->assertDatabaseCount('product_popularity_events', 1);
        $this->get('/designs?bestsellers=1')->assertOk()->assertDontSee('PRIVATE CUSTOMER ART')->assertViewHas('products', fn ($products) => $products->total() === 0);
    }

    public function test_bestsellers_filter_ranks_only_qualifying_active_products_and_keeps_pagination(): void
    {
        for ($i = 0; $i < 14; $i++) {
            Product::create(['name' => 'Popular '.$i, 'slug' => 'popular-'.$i, 'art_key' => 'afterhours', 'active' => true, 'popularity_count' => 11 + $i]);
        }
        Product::create(['name' => 'Hidden popular', 'slug' => 'hidden-popular', 'active' => false, 'popularity_count' => 999]);
        $this->product->update(['popularity_count' => 10]);
        $this->get('/')->assertOk()->assertViewHas('best', fn ($best) => $best->pluck('popularity_count')->all() === [24, 23, 22, 21]);
        $this->get('/designs?bestsellers=1')->assertOk()->assertDontSee('Hidden popular')->assertViewHas('products', fn ($products) => $products->total() === 14 && $products->first()->slug === 'popular-13')->assertSee('bestsellers=1');
        $this->get('/designs?bestsellers=1&page=2')->assertOk()->assertViewHas('products', fn ($products) => $products->count() === 2 && $products->total() === 14);
        $this->get('/designs?bestsellers=1&q=Popular+13')->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 1 && $products->first()->slug === 'popular-13')->assertSee('name="bestsellers" value="1"', false);
        $this->withSession(['locale' => 'en'])->get('/designs?bestsellers=1')->assertOk()->assertSee('Bestseller');
    }

    public function test_migration_backfills_only_known_cart_and_order_events_without_quantity_inflation(): void
    {
        $this->add(['quantity' => 100]);
        $this->add();
        $this->postJson('/api/checkout', $this->checkout())->assertOk();
        $this->add(['quantity' => 100]);
        $this->assertSame(4, $this->product->fresh()->popularity_count);
        $migration = require database_path('migrations/2026_10_22_212546_add_product_popularity_tracking.php');
        $migration->down();
        $migration->up();
        $this->assertSame(2, $this->product->fresh()->popularity_count);
        $this->assertDatabaseCount('product_popularity_events', 2);
        $this->assertSame(1, DB::table('product_popularity_events')->where('kind', 'cart')->count());
        $this->assertSame(1, DB::table('product_popularity_events')->where('kind', 'order')->count());
    }
}
