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
use App\Services\NameTextRenderer;
use App\Services\ReadyNamePersonalization;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NamePositionTest extends TestCase
{
    use RefreshDatabase;

    private function product(bool $measuresName = true): Product
    {
        $this->seed();
        Storage::fake('local');
        $this->get('/editor')->assertOk();
        foreach (['single' => 12000, 'double' => 20000, 'mixed' => 25000] as $mode => $price) {
            StoreSettings::put('price_'.$mode.'_iqd', $price);
        }
        StoreSettings::put('accepting_orders', true);
        Material::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'SOFTWARE TEST ONLY']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000]);
        $product = Product::firstOrFail();
        $product->update(['personalization_enabled' => true, 'personalization_layout' => ReadyNamePersonalization::DEFAULT_LAYOUT]);
        $renderer = $this->mock(NameTextRenderer::class);
        if ($measuresName) {
            $renderer->shouldReceive('render')->once()->andReturnUsing(function (array $element, array $fonts, string $directory): void {
                $width = (int) round($element['width'] / 25.4 * 600);
                $height = (int) round($element['height'] / 25.4 * 600);
                $image = imagecreatetruecolor($width, $height);
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
                imagefilledrectangle($image, 300, 70, 799, 180, imagecolorallocatealpha($image, 0, 0, 0, 0));
                ob_start();
                imagepng($image);
                Storage::disk('local')->put($directory.'/name.png', ob_get_clean());
            });
        } else {
            $renderer->shouldNotReceive('render');
        }

        return $product;
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1, 'front_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('kind', 'no_chip'))->value('id'), 'personalization_name' => 'محمد علي', 'personalization_font' => 'Tajawal', 'personalization_x' => -8, 'personalization_y' => 33], $overrides);
    }

    public function test_customer_moves_the_visible_name_near_the_edge_and_the_original_art_stays_locked(): void
    {
        $product = $this->product();
        $input = $this->input();
        $this->postJson('/api/products/'.$product->id.'/quote', $input)->assertOk()->assertJsonPath('preview.options.personalization.placement_valid', true)->assertJsonPath('preview.document.sides.front.elements.1.x', -8);

        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();

        $item = CartItem::firstOrFail();
        $name = $item->version->document['sides']['front']['elements'][1];
        $this->assertSame(-8, $name['x']);
        $this->assertSame(33, $name['y']);
        $this->assertTrue($item->version->document['sides']['front']['elements'][0]['locked']);
        $this->assertTrue($item->version->options['personalization']['positioned_by_customer']);
        $this->get('/products/'.$product->slug.'?cart='.$item->id)->assertOk()->assertSee('name="personalization_x" value="-8"', false);
    }

    #[TestWith([-30, 33])]
    #[TestWith([-8, -10])]
    public function test_outside_placements_stay_editable_in_preview_but_cannot_be_added_to_cart(int $x, int $y): void
    {
        $product = $this->product();
        $input = $this->input(['personalization_x' => $x, 'personalization_y' => $y]);

        $this->postJson('/api/products/'.$product->id.'/quote', $input)->assertOk()->assertJsonPath('preview.options.personalization.placement_valid', false);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertUnprocessable()->assertJsonValidationErrors('personalization_x');

        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('designs', 0);
    }

    public function test_a_name_over_the_chip_opening_is_rejected_by_the_server(): void
    {
        $product = $this->product();
        $template = TemplateVersion::whereHas('template', fn ($query) => $query->where('kind', 'small_chip'))->value('id');

        $this->postJson('/api/products/'.$product->id.'/cart', $this->input(['front_template_id' => $template, 'personalization_x' => 0, 'personalization_y' => 17]))->assertUnprocessable()->assertJsonValidationErrors('personalization_x');

        $this->assertDatabaseCount('cart_items', 0);
    }

    #[TestWith(['personalization_x', 'bad'])]
    #[TestWith(['personalization_x', -100])]
    #[TestWith(['personalization_y', null])]
    public function test_malformed_or_incomplete_coordinates_return_422_before_rendering(string $field, mixed $value): void
    {
        $product = $this->product(false);

        $this->postJson('/api/products/'.$product->id.'/cart', $this->input([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_cart_edit_preserves_the_old_position_and_checkout_freezes_the_new_position(): void
    {
        Queue::fake([ExportOrderItem::class]);
        $product = $this->product();
        $this->postJson('/api/products/'.$product->id.'/cart', $this->input())->assertOk();
        $item = CartItem::firstOrFail();
        $original = $item->version;

        $this->postJson('/api/products/'.$product->id.'/cart', $this->input(['cart_item_id' => $item->id, 'personalization_x' => 2, 'personalization_y' => 37]))->assertOk();

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(-8, $original->fresh()->document['sides']['front']['elements'][1]['x']);
        $this->postJson('/api/checkout', ['request_id' => (string) Str::uuid(), 'name' => 'Position test customer', 'phone' => '07701234567', 'governorate' => 'بغداد', 'district' => 'المنصور', 'address' => 'Test address', 'rights' => true, 'confirmed' => true])->assertOk();
        $orderItem = OrderItem::firstOrFail();
        $snapshot = $orderItem->snapshot;
        $this->assertSame(2, $snapshot['document']['sides']['front']['elements'][1]['x']);
        $this->assertSame(37, $snapshot['document']['sides']['front']['elements'][1]['y']);
        $product->update(['personalization_layout' => array_replace(ReadyNamePersonalization::DEFAULT_LAYOUT, ['y' => 25])]);
        $this->assertSame($snapshot, $orderItem->fresh()->snapshot);
        Queue::assertPushed(ExportOrderItem::class);
    }

    public function test_transparent_side_has_no_name_when_a_position_is_selected(): void
    {
        $product = $this->product();
        $back = TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'back'))->value('id');

        $this->postJson('/api/products/'.$product->id.'/quote', $this->input(['mode' => 'mixed', 'back_template_id' => $back]))->assertOk()->assertJsonCount(0, 'preview.document.sides.front.elements')->assertJsonPath('preview.document.sides.back.elements.1.x', -8);
    }
}
