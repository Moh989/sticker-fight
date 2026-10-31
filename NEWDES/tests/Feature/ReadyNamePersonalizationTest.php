<?php

namespace Tests\Feature;

use App\Jobs\ExportOrderItem;
use App\Models\CardTemplate;
use App\Models\CartItem;
use App\Models\Material;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\OrderRetention;
use App\Services\ReadyNamePersonalization;
use App\Services\StoreSettings;
use Database\Seeders\PersonalizedProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReadyNamePersonalizationTest extends TestCase
{
    use RefreshDatabase;

    private function product(bool $enabled = true): Product
    {
        $this->seed();
        $this->get('/editor')->assertOk();
        StoreSettings::put('price_single_iqd', 12000);
        StoreSettings::put('price_double_iqd', 20000);
        StoreSettings::put('price_mixed_iqd', 25000);
        StoreSettings::put('accepting_orders', true);
        Material::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'SOFTWARE TEST FIXTURE']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);
        $product = Product::firstOrFail();
        $product->update(['personalization_enabled' => $enabled, 'personalization_layout' => ReadyNamePersonalization::DEFAULT_LAYOUT]);

        return $product;
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1, 'front_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('kind', 'no_chip'))->value('id'), 'personalization_name' => 'محمد علي', 'personalization_font' => 'Tajawal'], $overrides);
    }

    #[TestWith(['محمد علي', 'Cairo', 'ar'])]
    #[TestWith(['محمد علي', 'Tajawal', 'ar'])]
    #[TestWith(['محمد علي', 'Amiri', 'ar'])]
    #[TestWith(['Ali Ahmed', 'Cairo', 'en'])]
    #[TestWith(['Ali Ahmed', 'Lato', 'en'])]
    #[TestWith(['Ali Ahmed', 'Bebas Neue', 'en'])]
    #[TestWith(['محمد علي', 'Rakkas', 'ar'])]
    #[TestWith(['محمد علي', 'El Messiri', 'ar'])]
    #[TestWith(['Ali Ahmed', 'Pacifico', 'en'])]
    #[TestWith(['Ali Ahmed', 'Playfair Display', 'en'])]
    public function test_name_and_language_font_are_saved_on_top_of_locked_art(string $name, string $font, string $language): void
    {
        $product = $this->product();
        $input = $this->input(['personalization_name' => $name, 'personalization_font' => $font]);

        $this->postJson('/api/products/'.$product->id.'/quote', $input)->assertOk()->assertJsonPath('unit_iqd', 12000)->assertJsonPath('preview.options.personalization.language', $language)->assertJsonPath('preview.document.sides.front.elements.1.text', $name);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();

        $version = CartItem::firstOrFail()->version;
        $this->assertTrue($version->options['ready_locked']);
        $this->assertSame($product->art_key, $version->document['sides']['front']['elements'][0]['art_key']);
        $text = $version->document['sides']['front']['elements'][1];
        $this->assertSame($name, $text['text']);
        $this->assertSame($font, $text['font']);
        $this->assertSame(12, $text['x']);
        $this->assertTrue($text['locked']);
        $this->get('/cart')->assertOk()->assertSee($name)->assertSee($font)->assertDontSee('/editor/');
        $this->putJson('/api/designs/'.$version->design_id, [])->assertForbidden();
    }

    #[TestWith(['محمد', 'Lato', 'personalization_font'])]
    #[TestWith(['Ali', 'Amiri', 'personalization_font'])]
    #[TestWith(['Ali', 'Unknown font', 'personalization_font'])]
    #[TestWith(['<script>alert(1)</script>', 'Cairo', 'personalization_name'])]
    #[TestWith(["Ali\nAhmed", 'Lato', 'personalization_name'])]
    #[TestWith(['12345', 'Lato', 'personalization_name'])]
    #[TestWith(['', 'Cairo', 'personalization_name'])]
    #[TestWith(['This name is longer than thirty characters', 'Lato', 'personalization_name'])]
    public function test_invalid_names_or_fonts_return_422_without_a_saved_design(string $name, string $font, string $field): void
    {
        $product = $this->product();

        $this->postJson('/api/products/'.$product->id.'/cart', $this->input(['personalization_name' => $name, 'personalization_font' => $font]))->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('designs', 0);
    }

    public function test_plain_ready_products_cannot_be_personalized_without_admin_enabling_it(): void
    {
        $product = $this->product(false);

        $this->get('/products/'.$product->slug)->assertOk()->assertDontSee('data-name-personalization', false);
        $this->postJson('/api/products/'.$product->id.'/cart', $this->input())->assertUnprocessable()->assertJsonValidationErrors('personalization_name');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_retries_do_not_duplicate_and_cart_edit_replaces_only_the_name_version(): void
    {
        $product = $this->product();
        $input = $this->input();
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('design_versions', 1);
        $this->postJson('/api/products/'.$product->id.'/cart', array_replace($input, ['personalization_name' => 'محمد حسن']))->assertConflict();
        $item = CartItem::firstOrFail();
        $oldVersion = $item->version;

        $this->postJson('/api/products/'.$product->id.'/cart', $this->input(['cart_item_id' => $item->id, 'personalization_name' => 'Ali Ahmed', 'personalization_font' => 'Lato']))->assertOk();

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertNotSame($oldVersion->id, $item->fresh()->design_version_id);
        $this->assertSame('محمد علي', $oldVersion->fresh()->options['personalization']['name']);
        $this->assertSame('Ali Ahmed', $item->fresh()->version->options['personalization']['name']);
        $this->get('/products/'.$product->slug.'?cart='.$item->id)->assertOk()->assertSee('Ali Ahmed');
    }

    public function test_checkout_freezes_name_position_and_font_bytes_when_product_changes_later(): void
    {
        Storage::fake('local');
        Queue::fake([ExportOrderItem::class]);
        $product = $this->product();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->input())->assertOk();

        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'Name test customer', 'phone' => '07701234567', 'governorate' => 'بغداد', 'district' => 'المنصور', 'address' => 'Test address', 'rights' => true, 'confirmed' => true])->assertOk();

        $item = OrderItem::firstOrFail();
        $snapshot = $item->snapshot;
        $font = $snapshot['fonts']['Tajawal']['files'][0];
        $this->assertSame(hash_file('sha256', public_path('fonts/Tajawal-Regular.ttf')), $font['sha256']);
        $this->assertSame(file_get_contents(public_path('fonts/Tajawal-Regular.ttf')), Storage::disk('local')->get($font['path']));
        $product->update(['personalization_enabled' => false, 'personalization_layout' => array_replace(ReadyNamePersonalization::DEFAULT_LAYOUT, ['x' => 20]), 'art_key' => 'tiger']);
        $this->assertSame($snapshot, $item->fresh()->snapshot);
        $this->assertSame('محمد علي', $snapshot['options']['personalization']['name']);
        Queue::assertPushed(ExportOrderItem::class, fn ($job) => $job->itemId === $item->id);
    }

    public function test_customer_cannot_override_admin_typography_or_unrestricted_art_layers(): void
    {
        $product = $this->product();

        $this->postJson('/api/products/'.$product->id.'/quote', $this->input(['name_x' => 0, 'font_size' => 30]))->assertOk()->assertJsonPath('preview.document.sides.front.elements.1.x', 12);
        $this->postJson('/api/products/'.$product->id.'/cart', $this->input(['document' => ['sides' => []]]))->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_names_cannot_overlap_a_chip_hole_and_transparent_sides_remain_empty(): void
    {
        $product = $this->product();
        $layout = ReadyNamePersonalization::DEFAULT_LAYOUT;
        $product->update(['personalization_layout' => array_replace($layout, ['x' => 10, 'y' => 19, 'width' => 15, 'height' => 9])]);
        $smallChip = TemplateVersion::whereHas('template', fn ($query) => $query->where('kind', 'small_chip'))->value('id');
        $this->postJson('/api/products/'.$product->id.'/quote', $this->input(['front_template_id' => $smallChip]))->assertOk()->assertJsonPath('preview.options.personalization.placement_valid', false);
        $this->postJson('/api/products/'.$product->id.'/cart', $this->input(['front_template_id' => $smallChip]))->assertUnprocessable()->assertJsonValidationErrors('personalization_name');
        $product->update(['personalization_layout' => $layout]);
        $back = TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'back'))->value('id');

        $this->postJson('/api/products/'.$product->id.'/quote', $this->input(['mode' => 'mixed', 'back_template_id' => $back]))->assertOk()->assertJsonCount(0, 'preview.document.sides.front.elements')->assertJsonCount(2, 'preview.document.sides.back.elements');
    }

    public function test_only_admin_can_enable_name_personalization_and_validate_its_geometry(): void
    {
        $product = $this->product(false);
        $payload = ['name' => $product->name, 'slug' => $product->slug, 'art_key' => $product->art_key, 'active' => 1, 'personalization_enabled' => 1, 'name_x' => 12, 'name_y' => 33, 'name_width' => 61.6, 'name_height' => 12, 'name_font_size' => 4.5, 'name_color' => '#30262B'];
        $this->actingAs(User::factory()->create(['role' => 'orders']))->postJson('/admin/manage/products/'.$product->id, $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/manage/products/'.$product->id, $payload)->assertRedirect();
        $this->assertTrue($product->fresh()->personalization_enabled);
        $this->assertSame('#30262B', $product->fresh()->personalization_layout['color']);
        $this->get('/products/'.$product->slug)->assertOk()->assertSee('data-name-personalization', false);
        $this->postJson('/admin/manage/products/'.$product->id, array_replace($payload, ['name_x' => 80]))->assertUnprocessable()->assertJsonValidationErrors('name_width');
        $this->get('/?locale=ar')->assertOk()->assertSee('data-personalized-products', false);
        $this->get('/designs?personalized=1')->assertOk()->assertSee($product->name)->assertDontSee('مو اليوم');
    }

    public function test_initial_name_design_is_idempotent_and_preserves_owner_changes(): void
    {
        $this->seed();
        $this->seed(PersonalizedProductSeeder::class);
        $product = Product::where('slug', 'iraqi-name-card')->firstOrFail();
        $this->assertTrue($product->personalization_enabled);
        $this->assertNull($product->price_iqd);
        $product->update(['name' => 'Owner name design', 'price_iqd' => 15000, 'active' => false]);

        $this->seed(PersonalizedProductSeeder::class);

        $this->assertDatabaseCount('products', 9);
        $this->assertSame('Owner name design', $product->fresh()->name);
        $this->assertSame(15000, $product->fresh()->price_iqd);
        $this->assertFalse($product->fresh()->active);
    }

    public function test_configured_order_retention_removes_printed_names_and_frozen_font_files(): void
    {
        Storage::fake('local');
        Queue::fake([ExportOrderItem::class]);
        $product = $this->product();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->input())->assertOk();
        $version = CartItem::firstOrFail()->version;
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'Retention test customer', 'phone' => '07701234567', 'governorate' => 'بغداد', 'district' => 'المنصور', 'address' => 'Test address', 'rights' => true, 'confirmed' => true])->assertOk();
        $item = OrderItem::firstOrFail();
        $fontPath = $item->snapshot['fonts']['Tajawal']['files'][0]['path'];
        $item->order->update(['status' => 'delivered']);
        $this->travel(800)->days();

        $this->assertSame(1, app(OrderRetention::class)->archive());

        Storage::disk('local')->assertMissing($fontPath);
        $this->assertArrayNotHasKey('fonts', $item->fresh()->snapshot);
        $this->assertArrayNotHasKey('personalization', $item->fresh()->snapshot['options']);
        $this->assertArrayNotHasKey('personalization', $version->fresh()->options);
        $this->assertArrayNotHasKey('personalization', $version->fresh()->design->options);
        Queue::assertPushed(ExportOrderItem::class);
    }
}
