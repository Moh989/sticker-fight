<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\CatalogRequest;
use App\Models\DeviceModel;
use App\Models\DeviceTemplate;
use App\Models\GuestSession;
use App\Models\Product;
use App\Models\ProductSection;
use App\Models\User;
use App\Models\WholesaleTier;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NewdesCatalogRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function visitor(): string
    {
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', (string) Str::uuid()), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $guest->id]);

        return hash_hmac('sha256', 'sticker-fight:guest:'.$guest->id, (string) config('app.key'));
    }

    private function payload(string $workspace): array
    {
        return ['request_id' => (string) Str::uuid(), 'workspace' => $workspace, 'name' => 'Test studio', 'phone' => '07701234567', 'quantity' => 100, 'details' => 'Custom packaging labels for test studio.', 'shape' => 'Round', 'width_mm' => 50, 'height_mm' => 50, 'material' => 'Matte'];
    }

    public function test_wholesale_upload_is_private_normalized_and_available_only_to_authorized_staff(): void
    {
        Storage::fake('local');
        $section = ProductSection::factory()->create(['audience' => 'business', 'requires_model' => false]);
        $payload = $this->payload($this->visitor()) + ['section_id' => $section->id, 'attachment' => UploadedFile::fake()->image('logo.png', 1200, 1200), 'rights' => '1'];

        $this->post('/wholesale', $payload)->assertRedirect(route('wholesale.index').'#request')->assertSessionHasNoErrors();

        $record = CatalogRequest::firstOrFail();
        $this->assertSame('wholesale', $record->kind);
        $this->assertSame('+9647701234567', $record->phone);
        $this->assertSame('50.00', $record->width_mm);
        $this->assertSame('Matte', $record->material);
        $this->assertNotNull($record->snapshot['rights_confirmed_at']);
        Storage::disk('local')->assertExists($record->snapshot['attachment']['path']);
        $this->get('/media/'.$record->asset_id)->assertOk();
        $this->visitor();
        $this->get('/media/'.$record->asset_id)->assertNotFound();
        $this->get('/admin/catalog-requests/'.$record->id.'/attachment')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'orders']))->get('/admin/catalog-requests/'.$record->id.'/attachment')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    #[TestWith(['production'])]
    #[TestWith(['shipping'])]
    #[TestWith(['customer'])]
    public function test_other_roles_cannot_read_quote_attachments(string $role): void
    {
        $record = CatalogRequest::factory()->create();
        $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin/catalog-requests/'.$record->id.'/attachment')->assertForbidden();
    }

    public function test_upload_needs_rights_and_svg_is_rejected_without_creating_a_request(): void
    {
        Storage::fake('local');
        $section = ProductSection::factory()->create(['audience' => 'business', 'requires_model' => false]);
        $payload = $this->payload($this->visitor()) + ['section_id' => $section->id, 'attachment' => UploadedFile::fake()->image('logo.png')];

        $this->post('/wholesale', $payload)->assertRedirect()->assertSessionHasErrors('rights');
        $payload['rights'] = '1';
        $payload['attachment'] = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post('/wholesale', $payload)->assertRedirect()->assertSessionHasErrors('file');

        $this->assertDatabaseCount('catalog_requests', 0);
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_retries_are_idempotent_without_requiring_a_logo_or_rights_for_no_attachment(): void
    {
        $section = ProductSection::factory()->create(['audience' => 'business', 'requires_model' => false]);
        $payload = $this->payload($this->visitor()) + ['section_id' => $section->id];
        $this->post('/wholesale', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/wholesale', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('catalog_requests', 1);

        $payload['quantity'] = 200;
        $this->post('/wholesale', $payload)->assertConflict();
        $this->assertSame(100, CatalogRequest::first()->quantity);
    }

    public function test_device_request_freezes_the_selected_models_approved_template_and_rejects_foreign_models(): void
    {
        $section = ProductSection::factory()->create(['requires_model' => true]);
        $model = DeviceModel::factory()->create(['product_section_id' => $section->id]);
        $template = DeviceTemplate::factory()->create(['device_model_id' => $model->id, 'approved_at' => now(), 'sample_reference' => 'AUTOMATED TEST']);
        $foreign = DeviceModel::factory()->create();
        $payload = $this->payload($this->visitor()) + ['device_model_id' => $foreign->id];
        $this->post('/devices/'.$section->slug.'/requests', $payload)->assertNotFound();
        $payload['device_model_id'] = $model->id;

        $this->post('/devices/'.$section->slug.'/requests', $payload)->assertRedirect()->assertSessionHasNoErrors();

        $record = CatalogRequest::firstOrFail();
        $this->assertSame($model->name, $record->model);
        $this->assertSame($template->geometry, $record->snapshot['template']['geometry']);
        $template->update(['name' => 'Changed later']);
        $this->assertSame('Test device template', $record->fresh()->snapshot['template']['name']);
    }

    public function test_product_model_compatibility_is_checked_on_the_server(): void
    {
        $section = ProductSection::factory()->create(['requires_model' => true]);
        $allowed = DeviceModel::factory()->create(['product_section_id' => $section->id]);
        $wrong = DeviceModel::factory()->create(['product_section_id' => $section->id]);
        $product = Product::create(['name' => 'Test device art', 'slug' => 'device-art', 'art_key' => 'minimal', 'product_section_id' => $section->id]);
        $product->deviceModels()->attach($allowed);
        $payload = $this->payload($this->visitor()) + ['device_model_id' => $wrong->id, 'product_id' => $product->id];

        $this->post('/devices/'.$section->slug.'/requests', $payload)->assertRedirect()->assertSessionHasErrors('device_model_id');

        $this->assertDatabaseCount('catalog_requests', 0);
    }

    public function test_only_active_wholesale_tiers_for_active_services_are_shown(): void
    {
        $visible = WholesaleTier::factory()->create(['label' => 'Visible specification']);
        WholesaleTier::factory()->create(['label' => 'Hidden specification', 'active' => false]);
        WholesaleTier::factory()->create(['label' => 'Hidden service', 'product_section_id' => ProductSection::factory()->create(['active' => false])->id]);

        $this->get('/wholesale')->assertOk()->assertSee('Visible specification')->assertDontSee('Hidden specification')->assertDontSee('Hidden service');
    }

    public function test_admin_rejects_invalid_device_geometry_and_non_admin_cannot_manage_models(): void
    {
        $model = DeviceModel::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'orders']))->post('/admin/manage/device-models', ['name' => 'Forbidden'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['name' => 'Invalid template', 'device_model_id' => $model->id, 'version' => 1, 'geometry' => json_encode(['width' => 100, 'height' => 50, 'bleed' => 2, 'safe' => 3, 'holes' => [['x' => 95, 'y' => 0, 'width' => 20, 'height' => 5]]]), 'active' => '1'];

        $this->post('/admin/manage/device-templates', $data)->assertRedirect()->assertSessionHasErrors('geometry');

        $this->assertDatabaseCount('device_templates', 0);
    }

    public function test_admin_can_set_feature_order_and_maintain_device_models_without_code_changes(): void
    {
        $section = ProductSection::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/manage/device-models', ['name' => 'New controller', 'slug' => 'new-controller', 'product_section_id' => $section->id, 'manufacturer' => 'Test maker', 'active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('device_models', ['slug' => 'new-controller', 'active' => true]);

        $this->post('/admin/manage/products', ['name' => 'Featured art', 'slug' => 'featured-art', 'art_key' => 'minimal', 'is_featured' => 1, 'featured_order' => 7, 'active' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['slug' => 'featured-art', 'featured_order' => 7, 'is_featured' => true]);
    }

    public function test_quote_attachments_survive_draft_cleanup(): void
    {
        Storage::fake('local');
        $this->visitor();
        $asset = Asset::create(['id' => (string) Str::uuid(), 'guest_session_id' => session('guest_id'), 'path' => 'assets/quote.png', 'mime' => 'image/png', 'width' => 1, 'height' => 1, 'bytes' => 4, 'sha256' => hash('sha256', 'test'), 'updated_at' => now()->subDays(180)]);
        Storage::disk('local')->put($asset->path, 'test');
        CatalogRequest::factory()->create(['asset_id' => $asset->id]);

        $this->artisan('sticker:purge', ['--force' => true])->assertSuccessful();

        Storage::disk('local')->assertExists($asset->path);
        $this->assertModelExists($asset);
    }

    public function test_configured_installation_images_and_video_are_allowed_without_enabling_external_scripts(): void
    {
        StoreSettings::put('installation_steps', [['title' => 'Test step', 'body' => 'Test explanation', 'tip' => 'Test tip', 'alt' => 'Test image', 'image' => '01-match', 'image_url' => 'https://images.example.test/apply.png']]);
        StoreSettings::put('installation_video', 'https://video.example.test/apply.mp4');

        $response = $this->get('/installation')->assertOk()->assertSee('https://images.example.test/apply.png', false)->assertSee('https://video.example.test/apply.mp4', false);

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: blob: https://images.example.test;", $policy);
        $this->assertStringContainsString("media-src 'self' https://video.example.test;", $policy);
        $this->assertStringContainsString("script-src 'self';", $policy);
    }
}
