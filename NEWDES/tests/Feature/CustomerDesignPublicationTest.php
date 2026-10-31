<?php

namespace Tests\Feature;

use App\Jobs\ExportOrderItem;
use App\Jobs\PublishCustomerDesign;
use App\Models\CardTemplate;
use App\Models\CartItem;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\CustomerDesignPublisher;
use App\Services\ExportService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CustomerDesignPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function cart(string $mode = 'single', bool $ready = false): CartItem
    {
        $this->seed();
        Storage::fake('local');
        Queue::fake([ExportOrderItem::class, PublishCustomerDesign::class]);
        StoreSettings::put('accepting_orders', true);
        foreach (['single' => 7000, 'double' => 12000, 'mixed' => 10000] as $key => $price) {
            StoreSettings::put('price_'.$key.'_iqd', $price);
        }
        Material::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);
        $front = TemplateVersion::whereHas('template', fn ($q) => $q->where('side', 'front'))->value('id');
        $back = TemplateVersion::whereHas('template', fn ($q) => $q->where('side', 'back'))->value('id');
        if ($ready) {
            $this->postJson('/api/products/'.Product::firstOrFail()->id.'/cart', ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'front_template_id' => $front, 'quantity' => 1])->assertOk();
        } else {
            $id = (string) Str::uuid();
            $this->putJson('/api/designs/'.$id, ['name' => 'PRIVATE DRAFT NAME', 'revision' => 0, 'options' => ['mode' => $mode, 'printed_side' => 'front', 'transparent_side' => 'front', 'material_id' => Material::where('transparent', false)->value('id'), 'transparent_material_id' => Material::where('transparent', true)->value('id'), 'finish_id' => null, 'product_id' => null], 'document' => ['version' => 1, 'sides' => ['front' => ['template_version_id' => $front, 'background' => '#123456', 'elements' => []], 'back' => ['template_version_id' => $back, 'background' => '#ABCDEF', 'elements' => []]]]])->assertOk();
            $this->postJson('/api/cart', ['reviewed' => true, 'design_id' => $id, 'revision' => 1, 'quantity' => 1])->assertOk();
        }

        return CartItem::firstOrFail();
    }

    private function checkout(array $extra = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'name' => 'PRIVATE CUSTOMER', 'phone' => '07701234567', 'governorate_id' => 1, 'address' => 'Private test address', 'district' => 'PRIVATE DISTRICT', 'landmark' => 'PRIVATE LANDMARK', 'rights' => true, 'confirmed' => true], ...$extra];
    }

    private function fakeArtwork(array $sides): void
    {
        $image = UploadedFile::fake()->image('rendered.png', 1011, 638);
        $bytes = file_get_contents($image->getRealPath());
        $this->mock(ExportService::class)->shouldReceive('catalogArtwork')->once()->andReturnUsing(function (OrderItem $item, string $directory) use ($sides, $bytes): array {
            $files = [];
            foreach ($sides as $side) {
                $name = $side.'-catalog.png';
                Storage::disk('local')->put($directory.'/'.$name, $bytes);
                $files[] = ['kind' => 'catalog', 'side' => $side, 'name' => $name];
            }

            return $files;
        });
    }

    public function test_checkout_offers_a_private_by_default_choice_for_each_custom_design(): void
    {
        $cart = $this->cart();

        $this->get('/checkout')->assertOk()->assertViewHas('customItems', fn ($items): bool => $items->count() === 1)->assertSee('هل توافق على نشر تصميمك في موقعنا؟')->assertSee('publication_choices['.$cart->id.']', false)->assertSee('value="0" checked', false)->assertSee('تصاميمكم اللطيفة');
    }

    #[TestWith([null])]
    #[TestWith([false])]
    public function test_refusal_or_absent_consent_keeps_the_design_private_and_checkout_unchanged(?bool $choice): void
    {
        $cart = $this->cart();
        $extra = $choice === null ? [] : ['publication_choices' => [$cart->id => $choice]];

        $this->postJson('/api/checkout', $this->checkout($extra))->assertOk();

        $item = OrderItem::firstOrFail();
        $this->assertFalse($item->publication_consent);
        $this->assertNull($item->publication_consented_at);
        $this->assertSame('private', $item->publication_status);
        $this->assertSame(12000, Order::firstOrFail()->totals['total_iqd']);
        Queue::assertNotPushed(PublishCustomerDesign::class);
        Queue::assertPushed(ExportOrderItem::class);
    }

    public function test_checkout_retries_preserve_recorded_consent_and_queue_publication_once(): void
    {
        $cart = $this->cart();
        $input = $this->checkout(['publication_choices' => [$cart->id => true]]);

        $this->postJson('/api/checkout', $input)->assertOk();
        $this->postJson('/api/checkout', [...$input, 'publication_choices' => [$cart->id => false]])->assertOk();

        $item = OrderItem::firstOrFail();
        $this->assertTrue($item->publication_consent);
        $this->assertNotNull($item->publication_consented_at);
        $this->assertTrue($item->snapshot['publication']['consent']);
        $this->assertSame('pending', $item->publication_status);
        $this->assertDatabaseCount('orders', 1);
        Queue::assertPushed(PublishCustomerDesign::class, 1);
    }

    public function test_publication_cannot_target_another_visitors_cart_item(): void
    {
        $this->cart();

        $this->postJson('/api/checkout', $this->checkout(['publication_choices' => [(string) Str::uuid() => true]]))->assertUnprocessable()->assertJsonValidationErrors('publication_choices');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
        Queue::assertNotPushed(PublishCustomerDesign::class);
    }

    public function test_ready_designs_have_no_publication_prompt_and_cannot_be_shared_as_custom_designs(): void
    {
        $cart = $this->cart(ready: true);

        $this->get('/checkout')->assertOk()->assertDontSee('publication_choices[', false);
        $this->postJson('/api/checkout', $this->checkout(['publication_choices' => [$cart->id => true]]))->assertUnprocessable()->assertJsonValidationErrors('publication_choices');

        $this->assertDatabaseCount('orders', 0);
        Queue::assertNotPushed(PublishCustomerDesign::class);
    }

    #[TestWith(['single', ['front']])]
    #[TestWith(['double', ['front', 'back']])]
    #[TestWith(['mixed', ['back']])]
    public function test_consented_printed_sides_become_independent_catalog_products(string $mode, array $sides): void
    {
        $cart = $this->cart($mode);
        $this->postJson('/api/checkout', $this->checkout(['publication_choices' => [$cart->id => true]]))->assertOk();
        $item = OrderItem::firstOrFail();
        $snapshot = $item->snapshot;
        $this->fakeArtwork($sides);

        (new PublishCustomerDesign($item->id))->handle(app(CustomerDesignPublisher::class));

        $products = Product::where('catalog_source', CustomerDesignPublisher::SOURCE)->get();
        $this->assertCount(count($sides), $products);
        foreach ($products as $product) {
            $this->assertSame('تصاميمكم اللطيفة', $product->category->name);
            $this->assertTrue($product->active);
            $this->assertNull($product->price_iqd);
            $this->assertNull($product->description);
            $this->assertContains($product->catalog_metadata['side'], $sides);
            $this->assertStringNotContainsString('PRIVATE', json_encode($product->toArray()));
            Storage::disk('local')->assertExists($product->catalogAsset->path);
            $this->get('/catalog/'.$product->catalog_asset_id)->assertOk();
        }
        $this->assertSame($snapshot, $item->fresh()->snapshot);
        $this->assertSame('published', $item->fresh()->publication_status);
        $this->assertSame('pending', $item->fresh()->export_status);
        $this->get('/designs?category=your-cute-designs')->assertOk()->assertSee($products->first()->slug);
    }

    public function test_retries_preserve_admin_changes_and_do_not_restore_deleted_publications(): void
    {
        $cart = $this->cart();
        $this->postJson('/api/checkout', $this->checkout(['publication_choices' => [$cart->id => true]]))->assertOk();
        $item = OrderItem::firstOrFail();
        $this->fakeArtwork(['front']);
        $publisher = app(CustomerDesignPublisher::class);
        $publisher->publish($item);
        $product = Product::where('catalog_source', CustomerDesignPublisher::SOURCE)->firstOrFail();
        $product->update(['name' => 'Owner edit', 'price_iqd' => 9000, 'active' => false]);
        $product->delete();

        $publisher->publish($item);

        $this->assertSame(1, Product::withTrashed()->where('catalog_source', CustomerDesignPublisher::SOURCE)->count());
        $this->assertSoftDeleted($product);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Owner edit', 'price_iqd' => 9000, 'active' => false]);
    }

    public function test_incomplete_render_rolls_back_publication_without_failing_the_order(): void
    {
        $cart = $this->cart('double');
        $this->postJson('/api/checkout', $this->checkout(['publication_choices' => [$cart->id => true]]))->assertOk();
        $item = OrderItem::firstOrFail();
        $this->fakeArtwork(['front']);

        try {
            app(CustomerDesignPublisher::class)->publish($item);
            $this->fail('Incomplete output must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The shared artwork output is incomplete.', $exception->getMessage());
        }

        $this->assertSame('failed', $item->fresh()->publication_status);
        $this->assertSame('pending', $item->export_status);
        $this->assertSame('pending_confirmation', Order::firstOrFail()->status);
        $this->assertDatabaseMissing('products', ['catalog_source' => CustomerDesignPublisher::SOURCE]);
        $this->assertDatabaseCount('catalog_assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('catalog'));
    }

    public function test_jobs_without_explicit_consent_do_not_render_or_publish(): void
    {
        $this->cart();
        $this->postJson('/api/checkout', $this->checkout())->assertOk();
        $this->mock(ExportService::class)->shouldNotReceive('catalogArtwork');

        app(CustomerDesignPublisher::class)->publish(OrderItem::firstOrFail());

        $this->assertDatabaseMissing('products', ['catalog_source' => CustomerDesignPublisher::SOURCE]);
    }

    #[TestWith(['orders'])]
    #[TestWith(['production'])]
    #[TestWith(['shipping'])]
    #[TestWith(['customer'])]
    public function test_non_admin_roles_cannot_retry_publication(string $role): void
    {
        $this->cart();
        $this->postJson('/api/checkout', $this->checkout())->assertOk();
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->postJson('/admin/items/'.OrderItem::firstOrFail()->id.'/publication/retry')->assertForbidden();

        Queue::assertNotPushed(PublishCustomerDesign::class);
    }

    public function test_admin_cannot_grant_consent_through_the_retry_action(): void
    {
        $this->cart();
        $this->postJson('/api/checkout', $this->checkout())->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/admin/items/'.OrderItem::firstOrFail()->id.'/publication/retry')->assertUnprocessable()->assertJsonValidationErrors('publication');

        $this->assertFalse(OrderItem::firstOrFail()->publication_consent);
        Queue::assertNotPushed(PublishCustomerDesign::class);
    }

    public function test_a_mixed_cart_only_shares_the_consented_custom_item(): void
    {
        $cart = $this->cart();
        $front = $cart->version->document['sides']['front']['template_version_id'];
        $this->postJson('/api/products/'.Product::firstOrFail()->id.'/cart', ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'front_template_id' => $front, 'quantity' => 1])->assertOk();

        $this->postJson('/api/checkout', $this->checkout(['publication_choices' => [$cart->id => true]]))->assertOk();

        $this->assertSame(1, OrderItem::where('publication_consent', true)->count());
        $this->assertSame(1, OrderItem::where('publication_consent', false)->count());
        Queue::assertPushed(PublishCustomerDesign::class, 1);
    }

    public function test_ambiguous_consent_values_return_422_without_creating_an_order(): void
    {
        $cart = $this->cart();

        $this->postJson('/api/checkout', $this->checkout(['publication_choices' => [$cart->id => 'yes']]))->assertUnprocessable()->assertJsonValidationErrors('publication_choices.'.$cart->id);

        $this->assertDatabaseCount('orders', 0);
        Queue::assertNotPushed(PublishCustomerDesign::class);
    }

    public function test_an_admin_can_retry_a_failed_consented_publication(): void
    {
        $cart = $this->cart();
        $this->postJson('/api/checkout', $this->checkout(['publication_choices' => [$cart->id => true]]))->assertOk();
        $item = OrderItem::firstOrFail();
        $item->update(['publication_status' => 'failed']);
        Queue::fake([PublishCustomerDesign::class]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->from('/admin/orders/'.$item->order_id)->post('/admin/items/'.$item->id.'/publication/retry')->assertRedirect('/admin/orders/'.$item->order_id);

        $this->assertSame('pending', $item->fresh()->publication_status);
        $this->assertTrue($item->fresh()->publication_consent);
        Queue::assertPushed(PublishCustomerDesign::class, fn (PublishCustomerDesign $job): bool => $job->itemId === $item->id);
    }
}
