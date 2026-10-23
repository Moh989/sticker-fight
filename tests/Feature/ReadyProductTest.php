<?php

namespace Tests\Feature;

use App\Http\Controllers\CheckoutController;
use App\Jobs\ExportOrderItem;
use App\Models\Asset;
use App\Models\CardTemplate;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\DesignVersion;
use App\Models\GuestSession;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReadyProductTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Product
    {
        $this->seed();
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'ready-test'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $guest->id]);
        StoreSettings::put('accepting_orders', true);
        foreach (['single' => 7000, 'double' => 12000, 'mixed' => 10000] as $mode => $price) {
            StoreSettings::put('price_'.$mode.'_iqd', $price);
        }
        Material::query()->update(['active' => true, 'surcharge_iqd' => 0]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);

        return Product::firstOrFail();
    }

    private function orderOptions(string $mode = 'single'): array
    {
        return ['request_id' => (string) Str::uuid(), 'mode' => $mode, 'quantity' => 2, 'front_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'front'))->value('id'), 'back_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'back'))->value('id')];
    }

    #[TestWith(['single', 7000])]
    #[TestWith(['double', 12000])]
    #[TestWith(['mixed', 10000])]
    public function test_ready_design_buys_directly_with_fixed_art_and_server_selected_available_materials(string $mode, int $price): void
    {
        $product = $this->store();
        $input = $this->orderOptions($mode);
        $this->postJson('/api/products/'.$product->id.'/quote', $input)->assertOk()->assertJsonPath('unit_iqd', $price);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk()->assertJsonPath('redirect', url('/cart'));
        $item = CartItem::firstOrFail();
        $version = $item->version;
        $this->assertTrue($version->options['ready_locked']);
        $this->assertFalse(Material::findOrFail($version->options['material_id'])->transparent);
        foreach ($mode === 'single' ? ['front'] : ['front', 'back'] as $side) {
            $elements = $version->document['sides'][$side]['elements'];
            if ($mode === 'mixed' && $side === 'front') {
                $this->assertSame([], $elements);
                $this->assertTrue(Material::findOrFail($version->options['transparent_material_id'])->transparent);
            } else {
                $this->assertCount(1, $elements);
                $this->assertSame($product->art_key, $elements[0]['art_key']);
                $this->assertTrue($elements[0]['locked']);
            }
        }
        $this->get('/cart')->assertOk()->assertDontSee('/editor/')->assertSee('خيارات الطلب');
        $this->get('/editor/'.$version->design_id)->assertRedirect('/products/'.$product->slug);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();
        $this->assertSame(1, CartItem::count());
        $this->assertSame(1, DesignVersion::count());
    }

    public function test_ready_art_and_product_identity_cannot_be_changed_through_any_customer_save_endpoint(): void
    {
        $product = $this->store();
        $input = $this->orderOptions();
        $this->postJson('/api/products/'.$product->id.'/cart', [...$input, 'document' => ['changed' => true]])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertSame(0, Design::count());
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();
        $design = Design::firstOrFail();
        $original = $design->document;
        $customOptions = $design->options;
        $customOptions['product_id'] = null;
        unset($customOptions['ready_locked']);
        $document = $original;
        $document['sides']['front']['elements'] = [];
        $this->putJson('/api/designs/'.$design->id, ['name' => 'Changed', 'revision' => 1, 'document' => $document, 'options' => $customOptions])->assertForbidden();
        $this->assertSame($original, $design->fresh()->document);
        $this->assertSame(1, $design->fresh()->revision);
        $this->get('/editor?product='.$product->id)->assertRedirect('/products/'.$product->slug);
        $this->get('/products/'.$product->slug)->assertOk()->assertDontSee('id="editor-app"', false)->assertDontSee('خصّص هذا التصميم')->assertSee('ready-product-form');
    }

    public function test_ready_order_options_replace_the_owned_cart_item_without_editing_its_previous_art_version(): void
    {
        $product = $this->store();
        $input = $this->orderOptions();
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();
        $item = CartItem::firstOrFail();
        $oldVersion = $item->version;
        $this->postJson('/api/products/'.$product->id.'/cart', [...$this->orderOptions('double'), 'cart_item_id' => $item->id, 'quantity' => 3])->assertOk();
        $this->assertSame(1, CartItem::count());
        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertNotSame($oldVersion->id, $item->fresh()->design_version_id);
        $this->assertSame('single', $oldVersion->fresh()->options['mode']);
    }

    public function test_other_guests_cannot_replace_a_cart_item_or_reuse_its_request_id(): void
    {
        $product = $this->store();
        $input = $this->orderOptions();
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();
        $item = CartItem::firstOrFail();
        $other = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'another-ready-guest'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $other->id]);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertNotFound();
        $this->postJson('/api/products/'.$product->id.'/cart', [...$this->orderOptions(), 'cart_item_id' => $item->id])->assertNotFound();
        $this->get('/products/'.$product->slug.'?cart='.$item->id)->assertNotFound();
    }

    public function test_trial_templates_and_clear_materials_cannot_be_used_as_printable_stock(): void
    {
        $product = $this->store();
        $input = $this->orderOptions();
        $clear = Material::where('transparent', true)->value('id');
        $this->postJson('/api/products/'.$product->id.'/cart', [...$input, 'material_id' => $clear])->assertUnprocessable();
        TemplateVersion::query()->update(['approved_at' => null]);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertUnprocessable();
        $this->assertSame(0, CartItem::count());
        $this->assertSame(0, Design::count());
    }

    public function test_no_printable_material_is_explained_and_cannot_create_a_ready_order(): void
    {
        $product = $this->store();
        Material::query()->update(['transparent' => true]);
        $this->get('/products/'.$product->slug)->assertOk()->assertSee('لا توجد خامة طباعة متاحة حالياً.');
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertUnprocessable();
        $this->assertSame(0, CartItem::count());
    }

    public function test_uploaded_catalog_art_is_copied_privately_and_frozen_into_the_order(): void
    {
        $this->store();
        Storage::fake('local');
        Queue::fake([ExportOrderItem::class]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/manage/products', ['name' => 'Fixed artwork', 'slug' => 'fixed-artwork', 'active' => 1, 'art_file' => UploadedFile::fake()->image('catalog.png', 1200, 800)])->assertRedirect();
        $product = Product::where('slug', 'fixed-artwork')->firstOrFail();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();
        $element = CartItem::firstOrFail()->version->document['sides']['front']['elements'][0];
        $asset = Asset::findOrFail($element['asset_id']);
        $original = Storage::disk('local')->get($asset->path);
        $ready = CartItem::firstOrFail()->version;
        $customOptions = $ready->options;
        $customOptions['product_id'] = null;
        unset($customOptions['ready_locked']);
        $this->putJson('/api/designs/'.Str::uuid(), ['name' => 'Editable catalog image', 'revision' => 0, 'document' => $ready->document, 'options' => $customOptions])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'TEST', 'phone' => '07701234567', 'governorate_id' => 1, 'city' => 'TEST', 'district' => 'TEST', 'address' => 'TEST ADDRESS', 'landmark' => 'TEST', 'rights' => true, 'confirmed' => true])->assertOk();
        $item = OrderItem::firstOrFail();
        Storage::disk('local')->put($product->catalogAsset->path, 'catalog changed');
        $this->assertSame($original, Storage::disk('local')->get($item->snapshot['assets'][$asset->id]['path']));
        $this->get('/orders/'.$item->order_id.'/assets/'.$asset->id.'?token='.CheckoutController::token(Order::firstOrFail()))->assertOk();
        Queue::assertPushed(ExportOrderItem::class);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_personal_upload_remains_editable_when_its_bytes_match_catalog_art(bool $uploadFirst): void
    {
        $this->store();
        Storage::fake('local');
        $file = UploadedFile::fake()->image('my-photo.png', 1200, 800);
        $personalId = $uploadFirst ? $this->postJson('/api/assets', ['file' => $file, 'rights' => true])->assertCreated()->json('id') : null;
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/manage/products', ['name' => 'Same artwork', 'slug' => 'same-artwork', 'active' => 1, 'art_file' => $file])->assertRedirect();
        $product = Product::where('slug', 'same-artwork')->firstOrFail();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();
        $ready = CartItem::firstOrFail()->version;
        $personalId ??= $this->postJson('/api/assets', ['file' => $file, 'rights' => true])->assertCreated()->json('id');
        $options = $ready->options;
        $options['product_id'] = null;
        unset($options['ready_locked']);
        $document = $ready->document;
        $catalogCopyId = $document['sides']['front']['elements'][0]['asset_id'];
        $document['sides']['front']['elements'][0]['asset_id'] = $personalId;
        $document['sides']['front']['elements'][0]['locked'] = false;
        $document['sides']['front']['elements'][0]['rotation'] = 15;
        $id = (string) Str::uuid();
        $this->putJson('/api/designs/'.$id, ['name' => 'My own photo', 'revision' => 0, 'document' => $document, 'options' => $options])->assertOk();
        $this->postJson('/api/quote', ['name' => 'My own photo', 'revision' => 1, 'document' => $document, 'options' => $options])->assertOk();
        $this->postJson('/api/cart', ['design_id' => $id, 'revision' => 1, 'quantity' => 1])->assertOk();
        $this->assertNotSame($catalogCopyId, $personalId);
        $this->assertSame('upload', Asset::findOrFail($personalId)->source);
        $this->assertSame('catalog', Asset::findOrFail($catalogCopyId)->source);
        $this->assertSame(Asset::findOrFail($personalId)->sha256, Asset::findOrFail($catalogCopyId)->sha256);
        $this->postJson('/api/assets', ['file' => $file, 'rights' => true])->assertCreated()->assertJsonPath('id', $personalId)->assertJsonPath('replaces_asset_ids', [$catalogCopyId]);
        $this->assertSame(2, Asset::count());
        $this->putJson('/api/designs/'.Str::uuid(), ['name' => 'Copied ready asset', 'revision' => 0, 'document' => $ready->document, 'options' => $options])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame($catalogCopyId, $ready->fresh()->document['sides']['front']['elements'][0]['asset_id']);
        $this->assertTrue($ready->fresh()->document['sides']['front']['elements'][0]['locked']);
    }

    public function test_existing_catalog_assets_stay_locked_but_an_explicit_fresh_upload_is_independent(): void
    {
        $this->store();
        Storage::fake('local');
        $file = UploadedFile::fake()->image('existing-catalog.png', 800, 600);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/manage/products', ['name' => 'Legacy artwork', 'slug' => 'legacy-artwork', 'active' => 1, 'art_file' => $file])->assertRedirect();
        $original = Asset::firstOrFail();
        $normalId = $this->postJson('/api/assets', ['file' => UploadedFile::fake()->image('personal.png', 300, 200), 'rights' => true])->assertCreated()->json('id');
        $migration = require database_path('migrations/2026_10_22_214439_add_source_to_assets_table.php');
        $migration->down();
        $migration->up();
        $this->assertSame('catalog', $original->fresh()->source);
        $this->assertSame('upload', Asset::findOrFail($normalId)->source);
        $this->assertSame($original->sha256, hash('sha256', Storage::disk('local')->get($original->path)));
        $freshId = $this->postJson('/api/assets', ['file' => $file, 'rights' => true])->assertCreated()->json('id');
        $this->assertNotSame($original->id, $freshId);
        $this->assertSame('upload', Asset::findOrFail($freshId)->source);
        $this->assertSame($original->sha256, Asset::findOrFail($freshId)->sha256);
    }

    public function test_upload_repair_metadata_never_reveals_another_guests_catalog_asset_ids(): void
    {
        $this->store();
        Storage::fake('local');
        $file = UploadedFile::fake()->image('private-repair.png', 800, 600);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/manage/products', ['name' => 'Private repair', 'slug' => 'private-repair', 'active' => 1, 'art_file' => $file])->assertRedirect();
        $product = Product::where('slug', 'private-repair')->firstOrFail();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();
        $foreignId = CartItem::firstOrFail()->version->document['sides']['front']['elements'][0]['asset_id'];
        $this->post('/logout')->assertRedirect('/');
        $this->postJson('/api/assets', ['file' => $file, 'rights' => true])->assertCreated()->assertJsonPath('replaces_asset_ids', []);
        $this->get('/media/'.$foreignId)->assertNotFound();
    }

    public function test_rolling_back_asset_sources_cannot_silently_merge_private_uploads_with_ready_assets(): void
    {
        $this->store();
        Storage::fake('local');
        $file = UploadedFile::fake()->image('separate-origins.png', 800, 600);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/manage/products', ['name' => 'Separate origins', 'slug' => 'separate-origins', 'active' => 1, 'art_file' => $file])->assertRedirect();
        $product = Product::where('slug', 'separate-origins')->firstOrFail();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertOk();
        $migration = require database_path('migrations/2026_10_22_214439_add_source_to_assets_table.php');
        try {
            $migration->down();
            $this->fail('Distinct asset origins must not be merged during rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Preserve both assets', $exception->getMessage());
        }
        $this->assertSame(['catalog', 'upload'], DB::table('assets')->orderBy('source')->pluck('source')->all());
        $this->assertSame(1, CartItem::count());
    }

    public function test_admin_material_type_is_an_explicit_choice_and_saves_printed_stock_correctly(): void
    {
        $this->store();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $material = Material::where('transparent', false)->firstOrFail();
        $this->get('/admin/manage/materials/'.$material->id)->assertOk()->assertSee('خامة مطبوعة')->assertSee('name="transparent" required', false);
        $this->post('/admin/manage/materials/'.$material->id, ['name' => 'Printed vinyl', 'code' => $material->code, 'surcharge_iqd' => 0, 'transparent' => '0', 'active' => 1])->assertRedirect();
        $this->assertFalse($material->fresh()->transparent);
    }

    public function test_the_custom_editor_cannot_offer_or_save_catalogue_art_as_an_editable_draft(): void
    {
        $this->store();
        $this->get('/editor')->assertOk()->assertDontSee('data-art=')->assertSee('image-upload');
        $source = $this->orderOptions();
        $product = Product::firstOrFail();
        $this->postJson('/api/products/'.$product->id.'/cart', $source)->assertOk();
        $ready = Design::firstOrFail();
        $options = $ready->options;
        $options['product_id'] = null;
        unset($options['ready_locked']);
        $this->putJson('/api/designs/'.Str::uuid(), ['name' => 'Editable copy', 'revision' => 0, 'document' => $ready->document, 'options' => $options])->assertUnprocessable();

        $this->assertSame(1, Design::count());
    }
}
