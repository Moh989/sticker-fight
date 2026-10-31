<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CatalogRequest;
use App\Models\GuestSession;
use App\Models\Product;
use App\Models\ProductSection;
use App\Models\User;
use App\Services\StoreSettings;
use Database\Seeders\ProductSectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DeviceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function guest(): string
    {
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', (string) Str::uuid()), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $guest->id]);

        return hash_hmac('sha256', 'sticker-fight:guest:'.$guest->id, (string) config('app.key'));
    }

    /** @return array<string, mixed> */
    private function payload(string $workspace): array
    {
        return ['request_id' => (string) Str::uuid(), 'workspace' => $workspace, 'name' => 'عميل اختبار', 'phone' => '٠٧٧٠١٢٣٤٥٦٧', 'model' => 'PS5 DualSense', 'quantity' => 2, 'details' => 'أريد ستيكر وردي وأسود ليد التحكم.'];
    }

    public function test_requests_created_together_have_distinct_display_references(): void
    {
        $records = CatalogRequest::factory()->count(2)->sequence(
            ['id' => '019a4e00-0000-7000-8000-000000000001'],
            ['id' => '019a4e00-0000-7000-8000-000000000002'],
        )->create();

        $this->assertNotSame($records[0]->number, $records[1]->number);
    }

    public function test_sections_are_seeded_once_and_owner_changes_survive_reseeding(): void
    {
        $this->seed(ProductSectionSeeder::class);
        $controllers = ProductSection::where('slug', 'controllers')->firstOrFail();
        $controllers->update(['description' => 'Owner text', 'sort_order' => 50, 'active' => false]);

        $this->seed(ProductSectionSeeder::class);

        $this->assertDatabaseCount('product_sections', 7);
        $this->assertSame('Owner text', $controllers->fresh()->description);
        $this->get('/devices')->assertOk()->assertDontSee('data-device-section="controllers"', false)->assertSee('data-device-section="consoles"', false)->assertSee('ستيكرات هولوغرام')->assertSee('ملصقات التغليف');
        $this->get('/devices/controllers')->assertNotFound();
        $this->get('/')->assertOk()->assertSee(route('devices.index'), false);
    }

    public function test_device_products_have_their_own_gallery_and_never_inherit_card_prices_or_templates(): void
    {
        $section = ProductSection::factory()->create();
        $device = Product::create(['name' => 'Custom controller skin', 'slug' => 'controller-skin', 'product_section_id' => $section->id, 'art_key' => 'minimal', 'active' => true, 'is_featured' => true]);
        Product::create(['name' => 'Card only', 'slug' => 'card-only', 'art_key' => 'minimal', 'active' => true]);
        StoreSettings::put('price_single_iqd', 12000);

        $this->get('/devices/'.$section->slug)->assertOk()->assertSee($device->name)->assertDontSee('Card only');
        $this->get('/products/controller-skin')->assertOk()->assertSee('اطلب تسعيرة لهذا التصميم')->assertSee('تصميم مميز')->assertDontSee('ready-product-form')->assertDontSee('mock-chip')->assertDontSee('12,000');
        $this->get('/designs')->assertOk()->assertSee('Card only')->assertDontSee($device->name);
        $this->assertNull($device->base_price_iqd);
        $device->update(['price_iqd' => 0]);
        $this->assertSame(0, $device->base_price_iqd);
        foreach (['quote', 'cart'] as $action) {
            $this->postJson('/api/products/'.$device->id.'/'.$action, ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1])->assertNotFound();
        }
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_hidden_sections_hide_their_products_assets_and_sitemap_links(): void
    {
        Storage::fake('local');
        $section = ProductSection::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/manage/products', ['name' => 'Portrait device artwork', 'slug' => 'portrait-device', 'product_section_id' => $section->id, 'active' => 1, 'art_file' => UploadedFile::fake()->image('device.png', 600, 1000)])->assertRedirect();
        $product = Product::firstOrFail();
        $this->get('/products/'.$product->slug)->assertOk()->assertSee($product->preview_image_url, false);
        $section->update(['active' => false]);

        $this->get('/products/'.$product->slug)->assertNotFound();
        $this->get('/catalog/'.$product->catalog_asset_id.'?preview=1')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/devices/'.$section->slug)->assertDontSee('/products/'.$product->slug);
    }

    public function test_request_normalizes_phone_stores_exact_measurements_and_ignores_client_prices_and_status(): void
    {
        $workspace = $this->guest();
        $section = ProductSection::factory()->create();
        $payload = $this->payload($workspace) + ['width_mm' => '95.50', 'height_mm' => '60.25', 'quoted_iqd' => 1, 'status' => 'quoted', 'staff_notes' => 'Injected'];

        $this->post('/devices/'.$section->slug.'/requests', $payload)->assertRedirect(route('devices.section', $section->slug).'#request')->assertSessionHas('success');

        $record = CatalogRequest::firstOrFail();
        $this->assertSame('+9647701234567', $record->phone);
        $this->assertSame('95.50', $record->width_mm);
        $this->assertSame('60.25', $record->height_mm);
        $this->assertSame(2, $record->quantity);
        $this->assertSame('new', $record->status);
        $this->assertNull($record->quoted_iqd);
        $this->assertNull($record->staff_notes);
        $this->assertSame(['section' => $section->name, 'product' => null, 'device_model' => null, 'template' => null, 'attachment' => null, 'rights_confirmed_at' => null], $record->snapshot);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_retries_are_idempotent_and_conflicting_submissions_return_409(): void
    {
        $section = ProductSection::factory()->create();
        $payload = $this->payload($this->guest());

        $this->post('/devices/'.$section->slug.'/requests', $payload)->assertRedirect();
        $this->post('/devices/'.$section->slug.'/requests', $payload)->assertRedirect();
        $this->postJson('/devices/'.$section->slug.'/requests', [...$payload, 'quantity' => 3])->assertConflict();

        $this->assertDatabaseCount('catalog_requests', 1);
        $this->assertSame(2, CatalogRequest::firstOrFail()->quantity);
    }

    public function test_another_guest_cannot_reuse_a_request_and_stale_browser_workspaces_return_409(): void
    {
        $section = ProductSection::factory()->create();
        $payload = $this->payload($this->guest());
        $this->post('/devices/'.$section->slug.'/requests', $payload)->assertRedirect();
        $secondWorkspace = $this->guest();

        $this->postJson('/devices/'.$section->slug.'/requests', $payload)->assertConflict();
        $this->postJson('/devices/'.$section->slug.'/requests', [...$payload, 'workspace' => $secondWorkspace])->assertNotFound();

        $this->assertDatabaseCount('catalog_requests', 1);
    }

    public function test_required_fields_and_device_model_are_validated_without_creating_a_request(): void
    {
        $section = ProductSection::factory()->create();

        $this->postJson('/devices/'.$section->slug.'/requests', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone', 'model', 'quantity', 'details', 'workspace', 'request_id'])->assertJsonPath('errors.model.0', 'اكتب موديل الجهاز بالتحديد.');

        $this->assertDatabaseCount('catalog_requests', 0);
    }

    #[TestWith(['phone', '123', 'اكتب رقم هاتف عراقي صحيحاً'])]
    #[TestWith(['quantity', 0, 'الكمية من 1 إلى 100000 قطعة.'])]
    #[TestWith(['details', 'قصير', 'اكتب تفاصيل الفكرة بعشر حروف على الأقل.'])]
    #[TestWith(['width_mm', 20, 'أكمل العرض والارتفاع معاً'])]
    public function test_invalid_request_details_return_422(string $field, mixed $value, string $message): void
    {
        $section = ProductSection::factory()->create();
        $payload = [...$this->payload($this->guest()), $field => $value];

        $this->postJson('/devices/'.$section->slug.'/requests', $payload)->assertUnprocessable()->assertJsonPath('message', fn (string $actual): bool => str_contains($actual, $message));

        $this->assertDatabaseCount('catalog_requests', 0);
    }

    public function test_products_from_another_section_or_deleted_products_cannot_be_requested(): void
    {
        $section = ProductSection::factory()->create();
        $other = ProductSection::factory()->create();
        $product = Product::create(['name' => 'Other section', 'slug' => 'other-section', 'product_section_id' => $other->id, 'art_key' => 'minimal', 'active' => true]);
        $payload = $this->payload($this->guest()) + ['product_id' => $product->id];

        $this->postJson('/devices/'.$section->slug.'/requests', $payload)->assertNotFound();
        $product->delete();
        $this->postJson('/devices/'.$other->slug.'/requests', $payload)->assertNotFound();

        $this->assertDatabaseCount('catalog_requests', 0);
    }

    public function test_non_device_sections_allow_requests_without_a_device_model(): void
    {
        $section = ProductSection::factory()->create(['requires_model' => false, 'illustration' => 'advertising']);
        $payload = $this->payload($this->guest());
        unset($payload['model']);

        $this->post('/devices/'.$section->slug.'/requests', $payload)->assertRedirect();

        $this->assertNull(CatalogRequest::firstOrFail()->model);
    }

    public function test_only_staff_can_read_requests_and_order_staff_can_quote_without_editing_customer_details(): void
    {
        $record = CatalogRequest::factory()->create(['name' => '<script>customer</script>', 'details' => '<script>private details</script>']);
        $this->get('/admin/catalog-requests')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'orders']));

        $this->get('/admin/catalog-requests?q='.$record->number)->assertOk()->assertSee($record->name)->assertDontSee('<script>customer</script>', false)->assertDontSee('<script>private details</script>', false);
        $this->postJson('/admin/catalog-requests/'.$record->id, ['status' => 'quoted'])->assertUnprocessable()->assertJsonValidationErrors('quoted_iqd');
        $this->from('/admin/catalog-requests')->post('/admin/catalog-requests/'.$record->id, ['status' => 'quoted', 'quoted_iqd' => 25000, 'staff_notes' => 'راجع القالب بعينة', 'name' => 'Changed'])->assertRedirect('/admin/catalog-requests');

        $this->assertSame('quoted', $record->fresh()->status);
        $this->assertSame(25000, $record->fresh()->quoted_iqd);
        $this->assertSame('<script>customer</script>', $record->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog_request.updated', 'subject_id' => $record->id]);
    }

    #[TestWith(['production'])]
    #[TestWith(['customer'])]
    public function test_unprivileged_roles_cannot_read_or_change_catalog_requests(string $role): void
    {
        $record = CatalogRequest::factory()->create();
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get('/admin/catalog-requests')->assertForbidden();
        $this->postJson('/admin/catalog-requests/'.$record->id, ['status' => 'closed'])->assertForbidden();
        $this->postJson('/admin/manage/sections', ['name' => 'Injected', 'slug' => 'injected'])->assertForbidden();

        $this->assertSame('new', $record->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_admin_can_manage_sections_and_assign_a_product_to_its_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/admin/manage/sections', ['name' => 'أجهزة أخرى', 'slug' => 'other-devices', 'description' => 'قسم قابل للإدارة', 'illustration' => 'console', 'requires_model' => 1, 'sort_order' => 8, 'active' => 1])->assertRedirect();
        $section = ProductSection::firstOrFail();

        $this->post('/admin/manage/products', ['name' => 'Console skin', 'slug' => 'console-skin', 'product_section_id' => $section->id, 'art_key' => 'minimal', 'active' => 1])->assertRedirect();

        $this->assertSame($section->id, Product::firstOrFail()->product_section_id);
        $this->get('/admin/manage/sections/'.$section->id)->assertOk()->assertSee('أجهزة أخرى');
        $this->get('/admin/manage/products')->assertOk()->assertSee('تسعير القسم');
    }
}
