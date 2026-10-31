<?php

namespace Tests\Feature;

use App\Models\CardTemplate;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\Finish;
use App\Models\GuestSession;
use App\Models\Material;
use App\Models\Product;
use App\Models\TemplateVersion;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TemplateCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Product
    {
        Cache::flush();
        $this->seed();
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'compatibility-test'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $guest->id]);
        foreach (['single' => 7000, 'double' => 12000, 'mixed' => 10000] as $mode => $price) {
            StoreSettings::put('price_'.$mode.'_iqd', $price);
        }
        Material::query()->update(['active' => true]);
        Finish::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);

        return Product::firstOrFail();
    }

    private function orderOptions(array $overrides = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1,
            'front_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'front'))->value('id'),
            'back_template_id' => TemplateVersion::whereHas('template', fn ($query) => $query->where('side', 'back'))->value('id'),
        ], ...$overrides];
    }

    public function test_ready_default_uses_a_compatible_material_even_when_an_unsupported_material_is_cheaper(): void
    {
        $product = $this->store();
        Material::where('transparent', false)->update(['surcharge_iqd' => 500]);
        Material::create(['name' => 'Unsupported new stock', 'code' => 'unsupported-stock', 'active' => true, 'transparent' => false, 'surcharge_iqd' => 0]);
        $this->postJson('/api/products/'.$product->id.'/quote', $this->orderOptions())->assertOk()->assertJsonPath('unit_iqd', 7500)->assertJsonPath('preview.options.material_id', Material::where('code', 'printed-vinyl')->value('id'));
    }

    public function test_explicit_unsupported_material_is_explained_and_never_silently_replaced(): void
    {
        $product = $this->store();
        $unsupported = Material::create(['name' => 'خامة غير مربوطة', 'code' => 'not-linked', 'active' => true, 'transparent' => false]);
        $input = $this->orderOptions(['material_id' => $unsupported->id]);
        foreach (['quote', 'cart'] as $action) {
            $this->postJson('/api/products/'.$product->id.'/'.$action, $input)->assertUnprocessable()->assertJsonValidationErrors('availability')->assertJsonPath('errors.availability.0', fn ($message) => str_contains($message, 'خامة غير مربوطة'));
        }
        $this->assertSame(0, CartItem::count());
        $this->assertSame(0, Design::count());
        $this->assertSame(0, $product->fresh()->popularity_count);
    }

    public function test_unsupported_mode_is_reported_as_a_mode_problem(): void
    {
        $product = $this->store();
        $input = $this->orderOptions(['mode' => 'double']);
        $version = TemplateVersion::findOrFail($input['front_template_id']);
        TemplateVersion::whereKey($version->id)->update(['compatibility' => [...$version->compatibility, 'modes' => ['single']]]);
        $this->postJson('/api/products/'.$product->id.'/quote', $input)->assertUnprocessable()->assertJsonValidationErrors('availability')->assertJsonPath('errors.availability.0', fn ($message) => str_contains($message, 'نوع الستيكر المختار'));
    }

    public function test_finish_rejected_by_the_printed_template_is_reported_as_a_finish_problem(): void
    {
        $product = $this->store();
        $input = $this->orderOptions(['finish_id' => Finish::value('id')]);
        $version = TemplateVersion::findOrFail($input['front_template_id']);
        TemplateVersion::whereKey($version->id)->update(['compatibility' => [...$version->compatibility, 'finish_ids' => []]]);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertUnprocessable()->assertJsonValidationErrors('availability')->assertJsonPath('errors.availability.0', fn ($message) => str_contains($message, 'التشطيب'));
        $this->assertSame(0, CartItem::count());
    }

    #[TestWith(['front'])]
    #[TestWith(['back'])]
    public function test_unprinted_clear_side_does_not_need_to_support_the_printed_sides_finish(string $clearSide): void
    {
        $product = $this->store();
        $finish = Finish::firstOrFail();
        $finish->update(['surcharge_iqd' => 200]);
        $input = $this->orderOptions(['mode' => 'mixed', 'transparent_side' => $clearSide, 'finish_id' => $finish->id, 'quantity' => 2]);
        $version = TemplateVersion::findOrFail($input[$clearSide.'_template_id']);
        TemplateVersion::whereKey($version->id)->update(['compatibility' => [...$version->compatibility, 'finish_ids' => []]]);
        $this->postJson('/api/products/'.$product->id.'/quote', $input)->assertOk()->assertJsonPath('unit_iqd', 10200)->assertJsonPath('subtotal_iqd', 20400);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();
        $this->assertSame([], CartItem::firstOrFail()->version->document['sides'][$clearSide]['elements']);
    }

    public function test_customer_can_fix_an_unsupported_material_without_losing_the_saved_artwork(): void
    {
        $this->store();
        $unsupported = Material::create(['name' => 'Unsupported custom stock', 'code' => 'unsupported-custom', 'active' => true, 'transparent' => false]);
        $input = $this->orderOptions();
        $id = (string) Str::uuid();
        $data = ['name' => 'Keep my artwork', 'revision' => 0, 'options' => ['mode' => 'single', 'printed_side' => 'front', 'transparent_side' => 'front', 'material_id' => $unsupported->id, 'transparent_material_id' => null, 'finish_id' => null, 'product_id' => null], 'document' => ['version' => 1, 'sides' => ['front' => ['template_version_id' => $input['front_template_id'], 'background' => '#FF2D8D', 'elements' => []], 'back' => ['template_version_id' => null, 'background' => null, 'elements' => []]]]];
        $this->putJson('/api/designs/'.$id, $data)->assertOk();
        $this->postJson('/api/quote', $data)->assertUnprocessable()->assertJsonValidationErrors('availability');
        $this->postJson('/api/cart', ['reviewed' => true, 'design_id' => $id, 'revision' => 1, 'quantity' => 1])->assertUnprocessable();
        $data['revision'] = 1;
        $data['options']['material_id'] = Material::where('code', 'printed-vinyl')->value('id');
        $this->putJson('/api/designs/'.$id, $data)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson('/api/quote', $data)->assertOk()->assertJsonPath('unit_iqd', 7000);
        $this->postJson('/api/cart', ['reviewed' => true, 'design_id' => $id, 'revision' => 2, 'quantity' => 1])->assertOk();
        $this->assertSame($data['document'], CartItem::firstOrFail()->version->document);
    }

    public function test_an_active_material_cannot_be_defaulted_when_no_selected_template_supports_it(): void
    {
        $product = $this->store();
        Material::where('transparent', false)->update(['active' => false]);
        Material::create(['name' => 'Active but unapproved stock', 'code' => 'unapproved-stock', 'active' => true, 'transparent' => false]);
        $this->postJson('/api/products/'.$product->id.'/cart', $this->orderOptions())->assertUnprocessable()->assertJsonValidationErrors('material_id');
        $this->assertSame(0, CartItem::count());
    }
}
