<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Design;
use App\Models\Material;
use App\Models\TemplateVersion;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomCartReviewTest extends TestCase
{
    use RefreshDatabase;

    private function draft(): Design
    {
        $this->seed();
        Material::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'SOFTWARE TEST ONLY']);
        StoreSettings::put('price_single_iqd', 8000);
        $this->get('/editor')->assertOk();
        $document = ['version' => 1, 'sides' => ['front' => ['template_version_id' => TemplateVersion::whereHas('template', fn ($q) => $q->where('kind', 'small_chip'))->value('id'), 'background' => '#52674F', 'elements' => []], 'back' => ['template_version_id' => null, 'background' => null, 'elements' => []]]];
        $options = ['mode' => 'single', 'printed_side' => 'front', 'transparent_side' => 'front', 'material_id' => Material::where('transparent', false)->value('id'), 'transparent_material_id' => null, 'finish_id' => null, 'product_id' => null];
        $id = (string) Str::uuid();
        $this->putJson('/api/designs/'.$id, ['name' => 'Test custom card', 'revision' => 0, 'document' => $document, 'options' => $options])->assertOk();

        return Design::findOrFail($id);
    }

    public function test_custom_design_requires_explicit_review_before_it_is_added(): void
    {
        $design = $this->draft();
        $this->get('/editor/'.$design->id)->assertOk()->assertSee('تم الحفظ على الخادم');
        $payload = ['design_id' => $design->id, 'revision' => 1, 'quantity' => 1];

        $this->postJson('/api/cart', $payload)->assertUnprocessable()->assertJsonValidationErrors('reviewed');
        $this->assertDatabaseCount('cart_items', 0);
        $this->postJson('/api/cart', $payload + ['reviewed' => true])->assertOk();

        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_editing_a_custom_cart_item_replaces_its_version_without_duplicating_the_item(): void
    {
        $design = $this->draft();
        $this->postJson('/api/cart', ['design_id' => $design->id, 'revision' => 1, 'quantity' => 2, 'reviewed' => true])->assertOk();
        $item = CartItem::firstOrFail();
        $oldVersion = $item->design_version_id;
        $document = $design->document;
        $document['sides']['front']['background'] = '#EAF0E5';
        $this->putJson('/api/designs/'.$design->id, ['name' => $design->name, 'revision' => 1, 'document' => $document, 'options' => $design->options])->assertOk();

        $this->postJson('/api/cart', ['design_id' => $design->id, 'revision' => 2, 'quantity' => 3, 'reviewed' => true, 'cart_item_id' => $item->id])->assertOk();

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertNotSame($oldVersion, $item->fresh()->design_version_id);
        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertSame('#52674F', $design->versions()->whereKey($oldVersion)->first()->document['sides']['front']['background']);
    }

    public function test_another_visitors_cart_item_cannot_be_replaced(): void
    {
        $design = $this->draft();
        $this->postJson('/api/cart', ['design_id' => $design->id, 'revision' => 1, 'quantity' => 1, 'reviewed' => true])->assertOk();
        $id = CartItem::first()->id;
        $this->post('/logout')->assertRedirect();
        $other = $this->draft();

        $this->postJson('/api/cart', ['design_id' => $other->id, 'revision' => 1, 'quantity' => 1, 'reviewed' => true, 'cart_item_id' => $id])->assertNotFound();

        $this->assertDatabaseCount('cart_items', 1);
    }
}
