<?php

namespace Tests\Feature;

use App\Http\Controllers\CheckoutController;
use App\Jobs\ExportOrderItem;
use App\Models\Asset;
use App\Models\Bundle;
use App\Models\CardTemplate;
use App\Models\CatalogAsset;
use App\Models\Collection;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Design;
use App\Models\GuestSession;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\AdminResources;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdditionalCommerceTest extends TestCase
{
    use RefreshDatabase;

    private function arrangeStore(): void
    {
        $this->seed();
        Cache::flush();
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'additional-test'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $guest->id]);
    }

    private function enableStore(): void
    {
        StoreSettings::put('price_single_iqd', 7000);
        StoreSettings::put('accepting_orders', true);
        Material::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'TEST ONLY']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);
    }

    private function saveDraft(array $elements = []): string
    {
        $id = (string) Str::uuid();
        $front = TemplateVersion::whereHas('template', fn ($q) => $q->where('side', 'front'))->value('id');
        $doc = ['version' => 1, 'sides' => ['front' => ['template_version_id' => $front, 'background' => '#18181E', 'elements' => $elements], 'back' => ['template_version_id' => null, 'background' => '#18181E', 'elements' => []]]];
        $this->putJson('/api/designs/'.$id, ['name' => 'TEST', 'revision' => 0, 'document' => $doc, 'options' => ['mode' => 'single', 'printed_side' => 'front', 'transparent_side' => 'front', 'material_id' => Material::where('transparent', false)->value('id'), 'transparent_material_id' => null, 'finish_id' => null, 'product_id' => null]])->assertOk();

        return $id;
    }

    public function test_catalog_upload_keeps_private_original_and_a_public_optimised_thumbnail(): void
    {
        $this->arrangeStore();
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/manage/products', ['name' => 'Licensed original', 'slug' => 'licensed-original', 'price_iqd' => 9000, 'active' => 1, 'art_file' => UploadedFile::fake()->image('art.png', 1700, 1000)])->assertRedirect();
        $product = Product::where('slug', 'licensed-original')->firstOrFail();
        $catalog = CatalogAsset::findOrFail($product->catalog_asset_id);
        Storage::disk('local')->assertExists($catalog->path);
        Storage::disk('local')->assertExists($catalog->thumbnail_path);
        $this->assertSame(1700, $catalog->width);
        $this->get('/catalog/'.$catalog->id)->assertOk()->assertHeader('content-type', 'image/webp');
        $preview = $this->get('/catalog/'.$catalog->id.'?preview=1')->assertOk()->assertHeader('content-type', 'image/webp');
        $dimensions = getimagesizefromstring($preview->streamedContent());
        $this->assertSame(1200, $dimensions[0]);
        $this->assertSame(706, $dimensions[1]);
        $this->get('/editor?product='.$product->id)->assertRedirect('/products/'.$product->slug);
        $this->get('/products/'.$product->slug)->assertOk()->assertSee('ready-product-form');
        $product->update(['active' => false]);
        $this->get('/catalog/'.$catalog->id)->assertNotFound();
    }

    public function test_bundle_discount_is_calculated_on_server_without_stacking_coupon(): void
    {
        $this->arrangeStore();
        $this->enableStore();
        Bundle::create(['name' => 'Three skin bundle', 'minimum_quantity' => 3, 'discount_percent' => 10, 'active' => true]);
        Coupon::create(['code' => 'SMALL', 'type' => 'fixed', 'value' => 500, 'active' => true]);
        $id = $this->saveDraft();
        $this->postJson('/api/cart', ['design_id' => $id, 'revision' => 1, 'quantity' => 3])->assertOk();
        $this->postJson('/api/checkout/quote', ['governorate_id' => 1, 'coupon' => 'SMALL'])->assertOk()->assertJsonPath('discount_iqd', 2100)->assertJsonPath('total_iqd', 23900)->assertJsonPath('bundle', 'Three skin bundle');
        $this->assertSame(0, Coupon::first()->used);
    }

    public function test_private_order_assets_open_on_another_device_only_with_tracking_token(): void
    {
        $this->arrangeStore();
        $this->enableStore();
        Storage::fake('local');
        Queue::fake([ExportOrderItem::class]);
        $response = $this->post('/api/assets', ['file' => UploadedFile::fake()->image('art.png', 900, 600), 'rights' => true], ['Accept' => 'application/json'])->assertCreated();
        $asset = Asset::findOrFail($response->json('id'));
        $original = Storage::disk('local')->get($asset->path);
        $id = $this->saveDraft([['id' => 'photo', 'type' => 'image', 'asset_id' => $asset->id, 'x' => -2, 'y' => -2, 'width' => 89.6, 'height' => 57.98, 'rotation' => 0, 'locked' => false, 'fit' => 'cover']]);
        $this->postJson('/api/cart', ['design_id' => $id, 'revision' => 1, 'quantity' => 1])->assertOk();
        $readyProduct = Product::where('art_key', 'orbit')->firstOrFail();
        $this->postJson('/api/products/'.$readyProduct->id.'/cart', ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1, 'front_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'front'))->value('id')])->assertOk();
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'TEST', 'phone' => '07701234567', 'governorate_id' => 1, 'city' => 'TEST', 'district' => 'TEST', 'address' => 'TEST ADDRESS', 'landmark' => 'TEST', 'rights' => 1, 'confirmed' => 1])->assertOk();
        $order = Order::firstOrFail();
        $token = CheckoutController::token($order);
        $item = OrderItem::where('design_version_id', Design::findOrFail($id)->versions()->value('id'))->firstOrFail();
        $this->assertNotSame($asset->path, $item->snapshot['assets'][$asset->id]['path']);
        Storage::disk('local')->put($asset->path, 'changed draft asset');
        $this->assertSame($original, Storage::disk('local')->get($item->snapshot['assets'][$asset->id]['path']));
        $other = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'other-device'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $other->id]);
        $this->get('/media/'.$asset->id)->assertNotFound();
        $this->get('/orders/'.$order->id.'/assets/'.$asset->id)->assertNotFound();
        $this->get('/orders/'.$order->id.'/assets/'.$asset->id.'?token='.$token)->assertOk();
        $this->get('/orders/'.$order->id.'/art/orbit?token='.$token)->assertOk();
        Queue::assertPushed(ExportOrderItem::class);
    }

    public function test_private_images_do_not_replace_the_previous_page_for_form_redirects(): void
    {
        $this->arrangeStore();
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/settings')->assertOk();
        $asset = Asset::create(['id' => (string) Str::uuid(), 'guest_session_id' => session('guest_id'), 'path' => 'test.png', 'mime' => 'image/png', 'width' => 1, 'height' => 1, 'bytes' => 1, 'sha256' => hash('sha256', 'test')]);
        Storage::disk('local')->put('test.png', 'x');
        $this->get('/admin/assets/'.$asset->id)->assertOk();
        $this->assertSame(url('/admin/settings'), session()->previousUrl());
    }

    public function test_english_uses_ltr_while_the_brand_name_stays_english(): void
    {
        $this->arrangeStore();
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()->assertSee('lang="en" dir="ltr"', false)->assertSee('Design your card')->assertSee('FIGHT BORING CARDS.');
        $this->withSession(['locale' => 'ar'])->get('/')->assertOk()->assertSee('lang="ar" dir="rtl"', false)->assertSee('صمّم بطاقتك');
    }

    public function test_customer_cannot_escalate_role_through_registration(): void
    {
        $this->arrangeStore();
        $this->post('/register', ['name' => 'Test', 'email' => 'customer@example.test', 'password' => 'secure-test-password', 'password_confirmation' => 'secure-test-password', 'role' => 'admin'])->assertRedirect('/saved');
        $this->assertSame('customer', User::where('email', 'customer@example.test')->firstOrFail()->role);
        $this->get('/admin')->assertForbidden();
    }

    public function test_sign_out_revokes_the_browser_guest_access_and_login_links_unowned_drafts(): void
    {
        $this->arrangeStore();
        $id = $this->saveDraft();
        $user = User::factory()->create(['password' => Hash::make('strong-password-test')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'strong-password-test'])->assertRedirect('/saved');
        $this->assertSame($user->id, Design::find($id)->user_id);
        $this->post('/logout')->assertRedirect('/');
        $this->getJson('/api/designs/'.$id)->assertNotFound();
        $this->post('/login', ['email' => $user->email, 'password' => 'strong-password-test'])->assertRedirect('/saved');
        $this->getJson('/api/designs/'.$id)->assertOk();
    }

    public function test_collection_references_cannot_double_count_and_refunds_use_the_separate_ledger(): void
    {
        $this->arrangeStore();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'TEST', 'phone' => '+9647701234567']);
        $order = Order::create(['id' => (string) Str::uuid(), 'number' => 'TEST-COLLECTION', 'tracking_hash' => hash('sha256', 'ledger-test'), 'guest_session_id' => session('guest_id'), 'customer_id' => $customer->id, 'request_id' => (string) Str::uuid(), 'address' => [], 'totals' => ['total_iqd' => 7000]]);
        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/payment', ['amount_iqd' => 4000, 'reference' => 'R1'])->assertRedirect();
        $this->post('/admin/orders/'.$order->id.'/payment', ['amount_iqd' => 4000, 'reference' => 'R1'])->assertSessionHasErrors('reference');
        $this->assertSame(4000, Collection::netFor($order->id));
        $this->post('/admin/orders/'.$order->id.'/refund', ['amount_iqd' => 1500, 'reference' => 'RF1'])->assertSessionHasErrors('refund');
        $order->update(['status' => 'cancelled']);
        $this->post('/admin/orders/'.$order->id.'/refund', ['amount_iqd' => 1500, 'reference' => 'RF1'])->assertRedirect();
        $this->assertSame(2500, Collection::netFor($order->id));
        $this->post('/admin/orders/'.$order->id.'/refund', ['amount_iqd' => 2500, 'reference' => 'RF2'])->assertRedirect();
        $this->assertSame(0, Collection::netFor($order->id));
        $this->assertSame('refunded', $order->fresh()->financial_status);
        $this->assertSame(3, Collection::count());
    }

    public function test_opt_in_order_retention_removes_old_artwork_without_removing_sales_totals(): void
    {
        $this->arrangeStore();
        $this->enableStore();
        Storage::fake('local');
        Queue::fake([ExportOrderItem::class]);
        $this->freezeTime();
        $product = Product::where('art_key', 'orbit')->firstOrFail();
        $this->postJson('/api/products/'.$product->id.'/cart', ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1, 'front_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'front'))->value('id')])->assertOk();
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'Private Name', 'phone' => '07701234567', 'governorate_id' => 1, 'city' => 'TEST', 'district' => 'TEST', 'address' => 'PRIVATE ADDRESS', 'landmark' => 'TEST', 'rights' => 1, 'confirmed' => 1])->assertOk();
        $order = Order::firstOrFail();
        $order->update(['status' => 'delivered']);
        $item = OrderItem::firstOrFail();
        $originalPath = $item->snapshot['art_assets']['orbit']['path'];
        Storage::disk('local')->assertExists($originalPath);
        $totals = $order->totals;
        $this->travel(800)->days();
        $this->artisan('sticker:purge', ['--orders' => true])->assertSuccessful();
        $this->assertSame('pending', $item->fresh()->export_status);
        $this->artisan('sticker:purge', ['--orders' => true, '--force' => true])->assertSuccessful();
        $this->assertSame('expired', $item->fresh()->export_status);
        $item->refresh();
        $this->assertArrayNotHasKey('document', $item->snapshot);
        Storage::disk('local')->assertMissing($originalPath);
        $this->assertSame($totals, $order->fresh()->totals);
        $this->get('/orders/'.$order->id.'?token='.CheckoutController::token($order))->assertOk()->assertSee('انتهت مدة الاحتفاظ');
        Queue::assertPushed(ExportOrderItem::class);
    }

    public function test_every_admin_resource_page_renders_and_does_not_only_validate_posts(): void
    {
        $this->arrangeStore();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        foreach (array_keys(AdminResources::all()) as $key) {
            $this->get('/admin/manage/'.$key)->assertOk();
        }$this->get('/admin/manage/products/1')->assertOk();
    }

    public function test_single_sided_order_ignores_an_unused_experimental_back_template(): void
    {
        $this->arrangeStore();
        $this->enableStore();
        Queue::fake([ExportOrderItem::class]);
        $id = $this->saveDraft();
        $design = Design::findOrFail($id);
        $back = TemplateVersion::whereHas('template', fn ($q) => $q->where('side', 'back'))->firstOrFail();
        TemplateVersion::whereKey($back->id)->update(['approved_at' => null]);
        $doc = $design->document;
        $doc['sides']['back']['template_version_id'] = $back->id;
        $this->putJson('/api/designs/'.$id, ['name' => 'TEST', 'revision' => 1, 'document' => $doc, 'options' => $design->options])->assertOk();
        $this->postJson('/api/cart', ['design_id' => $id, 'revision' => 2, 'quantity' => 1])->assertOk();
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'TEST', 'phone' => '07701234567', 'governorate_id' => 1, 'city' => 'TEST', 'district' => 'TEST', 'address' => 'TEST ADDRESS', 'landmark' => 'TEST', 'rights' => 1, 'confirmed' => 1])->assertOk();
        $item = OrderItem::firstOrFail();
        $this->assertSame(['front'], array_keys($item->snapshot['templates']));
        $this->assertSame(['front'], array_keys($item->snapshot['document']['sides']));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/orders/'.$item->order_id.'/status', ['status' => 'approved'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('approved', Order::first()->status);
        Queue::assertPushed(ExportOrderItem::class);
    }

    public function test_zero_total_order_has_no_outstanding_balance(): void
    {
        $this->arrangeStore();
        $this->enableStore();
        Queue::fake([ExportOrderItem::class]);
        StoreSettings::put('price_single_iqd', 0);
        ShippingRate::query()->update(['fee_iqd' => 0]);
        $id = $this->saveDraft();
        $this->postJson('/api/cart', ['design_id' => $id, 'revision' => 1, 'quantity' => 1])->assertOk();
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'TEST', 'phone' => '07701234567', 'governorate_id' => 1, 'city' => 'TEST', 'district' => 'TEST', 'address' => 'TEST ADDRESS', 'landmark' => 'TEST', 'rights' => 1, 'confirmed' => 1])->assertOk();
        $this->assertSame(0, Order::first()->totals['total_iqd']);
        $this->assertSame('paid', Order::first()->financial_status);
        Queue::assertPushed(ExportOrderItem::class);
    }

    public function test_transparent_background_draft_saves_but_an_empty_printed_side_cannot_be_ordered(): void
    {
        $this->arrangeStore();
        $this->enableStore();
        $id = $this->saveDraft();
        $design = Design::findOrFail($id);
        $doc = $design->document;
        $doc['sides']['front']['background'] = null;
        $this->putJson('/api/designs/'.$id, ['name' => 'Transparent blank', 'revision' => 1, 'options' => $design->options, 'document' => $doc])->assertOk();
        $this->assertNull($design->fresh()->document['sides']['front']['background']);
        $this->postJson('/api/cart', ['design_id' => $id, 'revision' => 2, 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('artwork');
    }
}
