<?php

namespace Tests\Feature;

use App\Http\Controllers\CheckoutController;
use App\Jobs\ExportOrderItem;
use App\Models\CardTemplate;
use App\Models\CartItem;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_catalog_image_has_a_preview_and_feature_control_in_admin_and_a_public_badge(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post('/admin/manage/products', ['name' => '<script>preview</script>', 'slug' => 'preview-design', 'active' => 1, 'is_featured' => 1, 'art_file' => UploadedFile::fake()->image('art.png', 1800, 1000)])->assertRedirect();

        $product = Product::firstOrFail();
        $this->assertTrue($product->is_featured);
        Storage::disk('local')->assertExists($product->catalogAsset->path);
        $this->get('/admin/manage/products/'.$product->id)->assertOk()->assertSee($product->preview_image_url, false)->assertSee('data-art-preview', false)->assertSee('معاينة الصورة المرفوعة')->assertSee('إلغاء التمييز')->assertSee('حذف التصميم')->assertDontSee('<script>preview</script>', false);
        $this->get('/designs')->assertOk()->assertSee('تصميم مميز')->assertDontSee('<script>preview</script>', false);
        $this->get('/products/'.$product->slug)->assertOk()->assertSee('تصميم مميز');
        $this->get('/catalog/'.$product->catalog_asset_id.'?preview=1')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertDatabaseHas('audit_logs', ['action' => 'resource.saved', 'subject_id' => (string) $product->id]);
    }

    public function test_feature_button_sets_an_explicit_state_without_changing_popularity_price_or_other_product_fields(): void
    {
        $product = Product::create(['name' => 'Featured', 'slug' => 'featured', 'art_key' => 'minimal', 'price_iqd' => 8000, 'active' => true, 'popularity_count' => 11]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->from('/admin/manage/products')->post('/admin/products/'.$product->id.'/feature', ['is_featured' => 1, 'active' => 0, 'price_iqd' => 1])->assertRedirect('/admin/manage/products');
        $this->post('/admin/products/'.$product->id.'/feature', ['is_featured' => 1])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_featured' => true, 'active' => true, 'price_iqd' => 8000, 'popularity_count' => 11]);
        $this->get('/designs')->assertOk()->assertSee('تصميم مميز')->assertSee('اكثر مبيعاً');
        $this->get('/products/'.$product->slug)->assertOk()->assertSee('تصميم مميز')->assertSee('اكثر مبيعاً');
        $this->post('/admin/products/'.$product->id.'/feature', ['is_featured' => 0])->assertRedirect();
        $this->get('/products/'.$product->slug)->assertOk()->assertDontSee('class="featured-badge"', false)->assertSee('اكثر مبيعاً');
        $this->assertFalse($product->fresh()->is_featured);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.featured_updated', 'subject_id' => (string) $product->id]);
    }

    #[TestWith([[]])]
    #[TestWith([['is_featured' => 'invalid']])]
    public function test_invalid_feature_state_returns_422_without_changing_the_product(array $input): void
    {
        $product = Product::create(['name' => 'Unchanged', 'slug' => 'unchanged', 'art_key' => 'minimal']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/products/'.$product->id.'/feature', $input)->assertUnprocessable()->assertJsonValidationErrors('is_featured');

        $this->assertFalse($product->fresh()->is_featured);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['customer'])]
    #[TestWith(['orders'])]
    #[TestWith(['production'])]
    public function test_only_admin_can_delete_or_feature_a_design(string $role): void
    {
        $product = Product::create(['name' => 'Protected', 'slug' => 'protected', 'art_key' => 'minimal']);
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->postJson('/admin/products/'.$product->id.'/feature', ['is_featured' => 1])->assertForbidden();
        $this->deleteJson('/admin/products/'.$product->id)->assertForbidden();

        $this->assertNotSoftDeleted($product);
        $this->assertFalse($product->fresh()->is_featured);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_guest_is_redirected_to_login_without_deleting_or_featuring_a_design(): void
    {
        $product = Product::create(['name' => 'Protected', 'slug' => 'protected', 'art_key' => 'minimal']);

        $this->post('/admin/products/'.$product->id.'/feature', ['is_featured' => 1])->assertRedirect('/login');
        $this->delete('/admin/products/'.$product->id)->assertRedirect('/login');

        $this->assertNotSoftDeleted($product);
        $this->assertFalse($product->fresh()->is_featured);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deleting_a_design_removes_it_from_sale_and_keeps_the_order_snapshot_and_private_art(): void
    {
        $this->seed();
        Storage::fake('local');
        Queue::fake([ExportOrderItem::class]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/manage/products', ['name' => 'Keep order art', 'slug' => 'keep-order-art', 'price_iqd' => 8000, 'active' => 1, 'is_featured' => 1, 'art_file' => UploadedFile::fake()->image('art.png', 1200, 760)])->assertRedirect();
        $product = Product::where('slug', 'keep-order-art')->firstOrFail();
        Material::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);
        StoreSettings::put('accepting_orders', true);
        $template = TemplateVersion::whereHas('template', fn ($query) => $query->where('kind', 'small_chip'))->value('id');
        $this->postJson('/api/products/'.$product->id.'/cart', ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'front_template_id' => $template, 'quantity' => 1])->assertOk();
        $this->get('/checkout')->assertOk()->assertSeeInOrder(['art/checkout-apply.webp', 'art/checkout-closeup.webp', 'id="place-order"'], false);
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'Test customer', 'phone' => '07701234567', 'governorate' => 'بغداد', 'district' => 'TEST', 'landmark' => 'TEST', 'rights' => 1, 'confirmed' => 1])->assertOk();
        $item = OrderItem::firstOrFail();
        $snapshot = $item->snapshot;
        $assetId = array_key_first($snapshot['assets']);
        $assetPath = $snapshot['assets'][$assetId]['path'];
        $artBytes = Storage::disk('local')->get($assetPath);

        $this->delete('/admin/products/'.$product->id)->assertRedirect('/admin/manage/products');

        $this->assertSoftDeleted($product);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'active' => false, 'is_featured' => false]);
        $this->assertSame($snapshot, $item->fresh()->snapshot);
        $this->assertSame($artBytes, Storage::disk('local')->get($assetPath));
        Storage::disk('local')->assertExists($product->catalogAsset->path);
        $this->get('/designs')->assertOk()->assertDontSee('keep-order-art');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('keep-order-art');
        $this->get('/products/keep-order-art')->assertNotFound();
        $this->postJson('/api/products/'.$product->id.'/quote', ['mode' => 'single', 'quantity' => 1, 'front_template_id' => $template])->assertNotFound();
        $this->get('/admin/manage/products/'.$product->id)->assertNotFound();
        $this->post('/admin/products/'.$product->id.'/feature', ['is_featured' => 1])->assertNotFound();
        $this->get('/admin/orders/'.$item->order_id)->assertOk()->assertSee('Keep order art');
        $order = Order::firstOrFail();
        $this->get('/orders/'.$order->id.'/assets/'.$assetId.'?token='.CheckoutController::token($order))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.deleted', 'subject_id' => (string) $product->id]);
        Queue::assertPushed(ExportOrderItem::class);
    }

    public function test_deleting_a_product_in_a_cart_preserves_art_but_blocks_checkout(): void
    {
        $this->seed();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $product = Product::firstOrFail();
        StoreSettings::put('price_single_iqd', 12000);
        Material::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);
        $this->postJson('/api/products/'.$product->id.'/cart', ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'front_template_id' => TemplateVersion::firstOrFail()->id, 'quantity' => 1])->assertOk();
        $version = CartItem::firstOrFail()->version;

        $this->delete('/admin/products/'.$product->id)->assertRedirect();

        $this->assertSame($version->document, $version->fresh()->document);
        $this->get('/cart')->assertOk()->assertSee('هذا التصميم غير متاح حالياً.');
        $this->postJson('/api/checkout/quote', ['governorate' => 'بغداد', 'district' => 'TEST'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_deleted_product_slug_stays_reserved_and_seeding_does_not_restore_it(): void
    {
        $this->seed();
        $product = Product::firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->delete('/admin/products/'.$product->id)->assertRedirect();

        $this->postJson('/admin/manage/products', ['name' => 'Duplicate link', 'slug' => $product->slug, 'art_key' => 'minimal'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->seed();

        $this->assertSoftDeleted($product);
        $this->assertDatabaseCount('products', 8);
        $this->assertDatabaseCount('catalog_assets', 0);
    }
}
