<?php

namespace Tests\Feature;

use App\Jobs\ExportOrderItem;
use App\Models\Area;
use App\Models\CardTemplate;
use App\Models\Coupon;
use App\Models\Design;
use App\Models\DesignVersion;
use App\Models\ExportRun;
use App\Models\Finish;
use App\Models\Governorate;
use App\Models\GuestSession;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\DesignValidator;
use App\Services\ExportService;
use App\Services\Phone;
use App\Services\PricingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    private GuestSession $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed();
        Queue::fake();
        Storage::fake('local');
        $this->guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'test-recovery'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $this->guest->id]);
    }

    private function enable(): void
    {
        foreach (['single' => 7000, 'double' => 12000, 'mixed' => 10000] as $mode => $price) {
            StoreSettings::put('price_'.$mode.'_iqd', $price);
        }StoreSettings::put('accepting_orders', true);
        Material::query()->update(['active' => true]);
        Finish::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'TEST FIXTURE — not a physical approval']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);
        Product::query()->update(['price_iqd' => 8000]);
        StoreSettings::put('extra_double_iqd', 4000);
        StoreSettings::put('extra_mixed_iqd', 3000);
    }

    private function data(string $mode = 'single'): array
    {
        return ['name' => 'تصميم عربي · MY CARD', 'revision' => 0, 'options' => ['mode' => $mode, 'printed_side' => 'front', 'transparent_side' => 'front', 'material_id' => Material::where('transparent', false)->value('id'), 'transparent_material_id' => Material::where('transparent', true)->value('id'), 'finish_id' => null, 'product_id' => null], 'document' => ['version' => 1, 'sides' => ['front' => ['template_version_id' => TemplateVersion::whereHas('template', fn ($q) => $q->where('side', 'front'))->value('id'), 'background' => '#18181E', 'elements' => []], 'back' => ['template_version_id' => TemplateVersion::whereHas('template', fn ($q) => $q->where('side', 'back'))->value('id'), 'background' => '#18181E', 'elements' => []]]]];
    }

    private function readyData(array $data, int $quantity = 1): array
    {
        return [...collect($data['options'])->except('product_id')->all(), 'front_template_id' => $data['document']['sides']['front']['template_version_id'], 'back_template_id' => $data['document']['sides']['back']['template_version_id'], 'quantity' => $quantity];
    }

    private function draft(array $data): Design
    {
        $id = (string) Str::uuid();
        $this->putJson('/api/designs/'.$id, $data)->assertOk()->assertJsonPath('revision', 1);

        return Design::findOrFail($id);
    }

    private function cart(Design $d, int $qty = 1): void
    {
        $this->postJson('/api/cart', ['design_id' => $d->id, 'revision' => $d->revision, 'quantity' => $qty, 'price_iqd' => 1])->assertOk();
    }

    private function checkout(array $extra = []): array
    {
        return array_merge(['request_id' => (string) Str::uuid(), 'name' => 'Test Customer', 'phone' => '٠٧٧٠١٢٣٤٥٦٧', 'governorate_id' => 1, 'city' => 'Test City', 'district' => 'Test District', 'address' => 'Test address only', 'landmark' => 'Test landmark', 'rights' => true, 'confirmed' => true], $extra);
    }

    public function test_public_pages_and_unset_commercial_data(): void
    {
        $this->get('/')->assertOk()->assertSee('STICKER')->assertSee('FIGHT')->assertDontSee('14,848');
        foreach (['/designs', '/products/afterhours', '/editor', '/guide', '/installation', '/faq', '/contact', '/saved', '/cart', '/login', '/track', '/sitemap.xml'] as $url) {
            $this->get($url)->assertOk();
        }$this->get('/privacy')->assertNotFound();
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_save_is_confirmed_idempotent_and_uses_optimistic_revision(): void
    {
        $data = $this->data();
        $d = $this->draft($data);
        $this->putJson('/api/designs/'.$d->id, $data)->assertOk()->assertJsonPath('revision', 1);
        $this->assertSame(1, DesignVersion::count());
        $data['name'] = 'Other';
        $this->putJson('/api/designs/'.$d->id, $data)->assertStatus(409);
        $data['revision'] = 1;
        $this->putJson('/api/designs/'.$d->id, $data)->assertOk()->assertJsonPath('revision', 2);
    }

    public function test_other_guest_cannot_read_edit_or_add_private_design(): void
    {
        $d = $this->draft($this->data());
        $other = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'other'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $other->id]);
        $this->getJson('/api/designs/'.$d->id)->assertNotFound();
        $this->putJson('/api/designs/'.$d->id, $this->data())->assertNotFound();
        $this->postJson('/api/cart', ['design_id' => $d->id, 'revision' => 1, 'quantity' => 1])->assertNotFound();
    }

    public function test_unapproved_templates_and_unset_prices_reject_purchase(): void
    {
        $d = $this->draft($this->data());
        $this->postJson('/api/cart', ['design_id' => $d->id, 'revision' => 1, 'quantity' => 1])->assertUnprocessable();
        StoreSettings::put('price_single_iqd', 7000);
        Material::query()->update(['active' => true]);
        $this->postJson('/api/cart', ['design_id' => $d->id, 'revision' => 1, 'quantity' => 1])->assertUnprocessable()->assertSee('availability');
    }

    public function test_pricing_is_integer_and_checks_modes_materials_and_templates(): void
    {
        $this->enable();
        $data = $this->data('double');
        $m = Material::find($data['options']['material_id']);
        $m->update(['surcharge_iqd' => 500]);
        $data['options']['finish_id'] = Finish::value('id');
        Finish::whereKey($data['options']['finish_id'])->update(['surcharge_iqd' => 200]);
        $t = app(DesignValidator::class)->templates($data['document']);
        $q = app(PricingService::class)->quote($data['options'], $t, 3);
        $this->assertSame(13400, $q['unit_iqd']);
        $this->assertSame(40200, $q['subtotal_iqd']);
        $d = $this->draft($data);
        $this->cart($d, 3);
        $this->postJson('/api/checkout/quote', ['governorate_id' => 1, 'subtotal_iqd' => 1])->assertOk()->assertJsonPath('total_iqd', 45200);
    }

    #[TestWith(['single', 7000])]
    #[TestWith(['double', 12000])]
    #[TestWith(['mixed', 10000])]
    public function test_ready_design_without_a_special_price_uses_the_general_price_for_its_mode(string $mode, int $price): void
    {
        $this->enable();
        $product = Product::firstOrFail();
        $product->update(['price_iqd' => null]);
        $data = $this->data($mode);
        $data['options']['product_id'] = $product->id;

        $this->postJson('/api/products/'.$product->id.'/quote', $this->readyData($data, 2))->assertOk()
            ->assertJsonPath('unit_iqd', $price)->assertJsonPath('subtotal_iqd', $price * 2);
        $this->postJson('/api/products/'.$product->id.'/cart', [...$this->readyData($data, 2), 'request_id' => (string) Str::uuid()])->assertOk();
        $this->get('/cart')->assertOk()->assertSee(number_format($price * 2));
        $this->postJson('/api/checkout/quote', ['governorate_id' => 1])->assertOk()
            ->assertJsonPath('subtotal_iqd', $price * 2)->assertJsonPath('total_iqd', $price * 2 + 5000);
        $this->postJson('/api/checkout', $this->checkout())->assertOk();
        $item = OrderItem::firstOrFail();
        $this->assertSame($price, $item->unit_price_iqd);
        StoreSettings::put('price_'.$mode.'_iqd', $price + 2000);
        $this->assertNull($product->fresh()->price_iqd);
        $this->assertSame($price, $item->fresh()->unit_price_iqd);
        $this->assertSame($price * 2 + 5000, Order::firstOrFail()->totals['total_iqd']);
    }

    #[TestWith(['single', 8000])]
    #[TestWith(['double', 12000])]
    #[TestWith(['mixed', 11000])]
    public function test_special_product_price_takes_precedence_over_general_prices(string $mode, int $price): void
    {
        $this->enable();
        StoreSettings::put('price_'.$mode.'_iqd', 99000);
        $data = $this->data($mode);
        $data['options']['product_id'] = Product::value('id');
        $this->postJson('/api/products/'.$data['options']['product_id'].'/quote', $this->readyData($data))->assertOk()->assertJsonPath('unit_iqd', $price);
    }

    public function test_a_zero_special_price_does_not_fall_back_to_the_general_price(): void
    {
        $this->enable();
        $product = Product::firstOrFail();
        $product->update(['price_iqd' => 0]);
        $data = $this->data();
        $data['options']['product_id'] = $product->id;
        $this->postJson('/api/products/'.$data['options']['product_id'].'/quote', $this->readyData($data))->assertOk()->assertJsonPath('unit_iqd', 0);
        $this->get('/products/'.$product->slug)->assertOk()->assertDontSee('سعر المنتج بانتظار');
    }

    public function test_general_price_fallback_does_not_allow_an_inactive_product_or_an_unconfigured_price(): void
    {
        $this->enable();
        $product = Product::firstOrFail();
        $product->update(['price_iqd' => null, 'active' => false]);
        $data = $this->data();
        $data['options']['product_id'] = $product->id;
        $this->postJson('/api/products/'.$data['options']['product_id'].'/quote', $this->readyData($data))->assertNotFound();
        $product->update(['active' => true]);
        StoreSettings::put('price_single_iqd', null);
        $this->postJson('/api/products/'.$data['options']['product_id'].'/quote', $this->readyData($data))->assertUnprocessable()->assertJsonValidationErrors('availability');
    }

    public function test_a_special_price_still_requires_the_configured_extra_for_additional_sides(): void
    {
        $this->enable();
        StoreSettings::put('extra_double_iqd', null);
        $data = $this->data('double');
        $data['options']['product_id'] = Product::value('id');
        $this->postJson('/api/products/'.$data['options']['product_id'].'/quote', $this->readyData($data))->assertUnprocessable()
            ->assertJsonValidationErrors('availability')->assertJsonPath('errors.availability.0', 'سعر الجهات الإضافية يحتاج إعداداً.');
    }

    public function test_gallery_prices_filters_and_sorting_use_the_effective_price(): void
    {
        StoreSettings::put('price_single_iqd', 12000);
        Product::query()->update(['active' => false]);
        $products = Product::orderBy('id')->limit(3)->get();
        $products[0]->update(['active' => true, 'price_iqd' => null]);
        $products[1]->update(['active' => true, 'price_iqd' => 8000]);
        $products[2]->update(['active' => true, 'price_iqd' => 20000]);

        $this->get('/products/'.$products[0]->slug)->assertOk()->assertSee('12,000')->assertDontSee('سعر المنتج بانتظار');
        $this->get('/designs?sort=price_asc&available=1')->assertOk()->assertSee('12,000')
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === [$products[1]->id, $products[0]->id, $products[2]->id]);
        $this->get('/designs?sort=price_desc&available=1')->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === [$products[2]->id, $products[0]->id, $products[1]->id]);
        StoreSettings::put('price_single_iqd', null);
        $this->get('/designs?available=1')->assertOk()->assertViewHas('products', fn ($rows) => $rows->total() === 2);
    }

    public function test_transparent_side_rejects_art_and_incompatible_material(): void
    {
        $data = $this->data('mixed');
        $data['document']['sides']['front']['elements'] = [['id' => 'art-1', 'type' => 'art', 'art_key' => 'orbit', 'x' => 0, 'y' => 0, 'width' => 85.6, 'height' => 53.98, 'rotation' => 0, 'locked' => false]];
        $this->putJson('/api/designs/'.Str::uuid(), $data)->assertUnprocessable();
        $this->enable();
        $data = $this->data();
        $data['options']['material_id'] = Material::where('transparent', true)->value('id');
        $d = $this->draft($data);
        $this->postJson('/api/cart', ['design_id' => $d->id, 'revision' => 1, 'quantity' => 1])->assertUnprocessable();
    }

    public function test_template_side_mismatch_is_rejected(): void
    {
        $data = $this->data();
        $data['document']['sides']['front']['template_version_id'] = $data['document']['sides']['back']['template_version_id'];
        $this->putJson('/api/designs/'.Str::uuid(), $data)->assertUnprocessable();
    }

    public function test_checkout_freezes_versions_and_is_idempotent_with_coupon(): void
    {
        $this->enable();
        $d = $this->draft($this->data('mixed'));
        $this->cart($d, 2);
        Coupon::create(['code' => 'TEST', 'type' => 'percent', 'value' => 10, 'active' => true, 'usage_limit' => 1]);
        $request = $this->checkout(['coupon' => 'TEST']);
        $response = $this->postJson('/api/checkout', $request)->assertOk();
        $url = $response->json('redirect');
        $this->assertSame(23000, Order::first()->totals['total_iqd']);
        $before = OrderItem::first()->snapshot;
        $data = $this->data('mixed');
        $data['revision'] = 1;
        $data['name'] = 'Modified draft';
        $this->putJson('/api/designs/'.$d->id, $data)->assertOk();
        $t = TemplateVersion::first();
        $geometry = $t->geometry;
        $geometry['width'] = 80;
        TemplateVersion::whereKey($t->id)->update(['geometry' => $geometry]);
        $this->assertSame($before, OrderItem::first()->snapshot);
        $this->postJson('/api/checkout', $request)->assertOk()->assertJsonPath('redirect', $url);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Coupon::first()->used);
        $this->get($url)->assertOk();
        $this->get('/orders/'.Order::first()->id)->assertNotFound();
        $this->get('/orders/'.Order::first()->id.'?token=bad')->assertNotFound();
        Queue::assertPushed(ExportOrderItem::class);
    }

    public function test_roles_and_order_transitions_enforce_export_and_hide_pii(): void
    {
        $this->enable();
        $d = $this->draft($this->data());
        $this->cart($d);
        $this->postJson('/api/checkout', $this->checkout())->assertOk();
        $o = Order::first();
        $production = User::factory()->create(['role' => 'production']);
        $this->actingAs($production)->get('/admin/orders/'.$o->id)->assertOk()->assertDontSee('Test Customer')->assertDontSee('+9647701234567');
        $this->get('/admin/settings')->assertForbidden();
        $this->post('/admin/orders/'.$o->id.'/status', ['status' => 'approved'])->assertSessionHasErrors('status');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/orders/'.$o->id.'/status', ['status' => 'approved'])->assertRedirect();
        $this->post('/admin/orders/'.$o->id.'/status', ['status' => 'printing'])->assertSessionHasErrors('status');
        OrderItem::query()->update(['export_status' => 'ready']);
        $this->post('/admin/orders/'.$o->id.'/status', ['status' => 'printing'])->assertRedirect();
        $this->assertSame('printing', $o->fresh()->status);
    }

    public function test_upload_rejects_svg_and_prevents_cross_guest_assets(): void
    {
        $this->post('/api/assets', ['file' => UploadedFile::fake()->createWithContent('bad.svg', '<svg onload="alert(1)"/>'), 'rights' => true], ['Accept' => 'application/json'])->assertUnprocessable();
        $upload = $this->post('/api/assets', ['file' => UploadedFile::fake()->image('image.png', 800, 500), 'rights' => true], ['Accept' => 'application/json'])->assertCreated();
        $id = $upload->json('id');
        $this->get('/media/'.$id)->assertOk();
        $other = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'other-upload'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $other->id]);
        $this->get('/media/'.$id)->assertNotFound();
        $d = $this->data();
        $d['document']['sides']['front']['elements'] = [['id' => 'photo', 'type' => 'image', 'asset_id' => $id, 'x' => 0, 'y' => 0, 'width' => 85, 'height' => 54, 'rotation' => 0, 'locked' => false]];
        $this->putJson('/api/designs/'.Str::uuid(), $d)->assertNotFound();
    }

    public function test_export_failure_stays_visible_without_advancing_order(): void
    {
        $this->enable();
        $d = $this->draft($this->data());
        $this->cart($d);
        $this->postJson('/api/checkout', $this->checkout())->assertOk();
        $item = OrderItem::first();
        $service = \Mockery::mock(ExportService::class);
        $service->shouldReceive('run')->once()->andThrow(new \RuntimeException('Simulated renderer failure'));
        try {
            (new ExportOrderItem($item->id))->handle($service);
            $this->fail('Expected export exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated renderer failure', $e->getMessage());
        }$this->assertSame('failed', $item->fresh()->export_status);
        $this->assertSame('pending_confirmation', Order::first()->status);
        $this->assertSame('failed', ExportRun::first()->status);
    }

    public function test_phone_normalization(): void
    {
        foreach (['07701234567', '+9647701234567', '00964 770 123 4567', '٠٧٧٠١٢٣٤٥٦٧', '۰۷۷۰۱۲۳۴۵۶۷'] as $phone) {
            $this->assertSame('+9647701234567', Phone::normalize($phone));
        }$this->expectException(ValidationException::class);
        Phone::normalize('12345');
    }

    private function simpleCheckout(array $extra = []): array
    {
        return array_merge(['request_id' => (string) Str::uuid(), 'name' => 'زبون اختبار', 'phone' => '07701234567', 'governorate' => 'بغداد', 'district' => 'المنصور', 'landmark' => 'قرب المدخل الرئيسي', 'rights' => true, 'confirmed' => true], $extra);
    }

    #[TestWith([null])]
    #[TestWith(['07801234567'])]
    public function test_simplified_checkout_saves_the_typed_address_without_city_or_street_fields(?string $alternatePhone): void
    {
        $this->enable();
        $this->cart($this->draft($this->data()));
        $input = $this->simpleCheckout(['alternate_phone' => $alternatePhone]);

        $this->postJson('/api/checkout', $input)->assertOk();

        $order = Order::with('customer')->firstOrFail();
        $this->assertSame('بغداد', $order->address['governorate']);
        $this->assertSame('المنصور', $order->address['district']);
        $this->assertSame('قرب المدخل الرئيسي', $order->address['landmark']);
        $this->assertArrayNotHasKey('city', $order->address);
        $this->assertArrayNotHasKey('address', $order->address);
        $this->assertSame($alternatePhone ? '+9647801234567' : null, $order->customer->alternate_phone);
        $this->assertSame(12000, $order->totals['total_iqd']);
        $this->assertDatabaseCount('cart_items', 0);
        Queue::assertPushed(ExportOrderItem::class);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/orders/'.$order->id)
            ->assertOk()->assertSee('المنصور')->assertSee('قرب المدخل الرئيسي');
    }

    #[TestWith(['name'])]
    #[TestWith(['phone'])]
    #[TestWith(['governorate'])]
    #[TestWith(['district'])]
    #[TestWith(['landmark'])]
    #[TestWith(['rights'])]
    #[TestWith(['confirmed'])]
    public function test_simplified_checkout_requires_each_contact_and_acknowledgement_field(string $field): void
    {
        $input = $this->simpleCheckout();
        unset($input[$field]);

        $this->postJson('/api/checkout', $input)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        Queue::assertNothingPushed();
    }

    #[TestWith(['rights'])]
    #[TestWith(['confirmed'])]
    public function test_checkout_rejects_an_unchecked_acknowledgement(string $field): void
    {
        $this->postJson('/api/checkout', $this->simpleCheckout([$field => false]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_typed_location_resolves_its_own_shipping_rate_and_ignores_conflicting_ids(): void
    {
        $this->enable();
        $this->cart($this->draft($this->data()));
        $governorate = Governorate::where('name', 'البصرة')->firstOrFail();
        $area = Area::create(['governorate_id' => $governorate->id, 'name' => 'منطقة الاختبار', 'active' => true]);
        $rate = ShippingRate::create(['governorate_id' => $governorate->id, 'area_id' => $area->id, 'fee_iqd' => 9000, 'active' => true]);
        $input = $this->simpleCheckout(['governorate' => 'محافظة البصره', 'district' => 'منطقة   الاختبار', 'governorate_id' => 1]);

        $this->postJson('/api/checkout/quote', $input)->assertOk()->assertJsonPath('shipping_iqd', 9000)->assertJsonPath('total_iqd', 16000);
        $this->postJson('/api/checkout', $input)->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame($governorate->id, $order->address['governorate_id']);
        $this->assertSame('البصرة', $order->address['governorate']);
        $this->assertSame($area->id, $order->address['area_id']);
        $this->assertSame($rate->id, $order->totals['shipping_rate_id']);
        $this->assertSame(9000, $order->totals['shipping_iqd']);
    }

    public function test_unknown_governorate_has_no_quote_or_order(): void
    {
        $input = $this->simpleCheckout(['governorate' => 'اسم غير معروف']);

        $this->postJson('/api/checkout/quote', $input)->assertUnprocessable()->assertJsonValidationErrors('governorate');
        $this->postJson('/api/checkout', $input)->assertUnprocessable()->assertJsonValidationErrors('governorate');

        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_inactive_typed_region_cannot_fall_back_to_a_general_delivery_fee(): void
    {
        $this->enable();
        $this->cart($this->draft($this->data()));
        Area::create(['governorate_id' => 1, 'name' => 'منطقة غير متاحة', 'active' => false]);

        $this->postJson('/api/checkout/quote', $this->simpleCheckout(['district' => 'منطقة غير متاحة']))
            ->assertUnprocessable()->assertJsonValidationErrors('availability');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_form_only_requests_the_six_contact_and_delivery_fields(): void
    {
        $this->enable();
        $this->cart($this->draft($this->data()));

        $this->get('/checkout')->assertOk()->assertSee('name="governorate"', false)
            ->assertSee('name="district"', false)->assertSee('name="landmark"', false)
            ->assertDontSee('name="city"', false)->assertDontSee('name="address"', false)
            ->assertDontSee('name="notes"', false)->assertDontSee('name="area_id"', false)
            ->assertSee('أقر بحقي في استخدام الصور والنصوص في هذا الطلب.');
    }
}
