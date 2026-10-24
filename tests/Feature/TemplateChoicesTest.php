<?php

namespace Tests\Feature;

use App\Models\CardTemplate;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\GuestSession;
use App\Models\Material;
use App\Models\Product;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TemplateChoicesTest extends TestCase
{
    use RefreshDatabase;

    private function store(): void
    {
        $this->seed();
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', 'template-choices-test'), 'last_seen_at' => now()]);
        $this->withSession(['guest_id' => $guest->id]);
        Material::query()->update(['active' => true]);
        StoreSettings::put('price_single_iqd', 12000);
    }

    private function version(string $kind): TemplateVersion
    {
        return TemplateVersion::whereHas('template', fn ($query) => $query->where('kind', $kind))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function designData(int $templateId): array
    {
        return ['name' => 'بطاقتي', 'revision' => 0, 'options' => ['mode' => 'single', 'printed_side' => 'front', 'transparent_side' => 'front', 'material_id' => Material::where('transparent', false)->value('id'), 'transparent_material_id' => null, 'finish_id' => null, 'product_id' => null], 'document' => ['version' => 1, 'sides' => ['front' => ['template_version_id' => $templateId, 'background' => '#FF2D8D', 'elements' => []], 'back' => ['template_version_id' => null, 'background' => null, 'elements' => []]]]];
    }

    public function test_fresh_catalog_has_only_two_chip_choices_with_distinct_openings_and_no_automatic_approval(): void
    {
        $this->store();

        $this->get('/editor')->assertOk()->assertViewHas('templates', function (Collection $templates): bool {
            $this->assertSame(['small_chip', 'no_chip'], $templates->pluck('kind')->all());
            $this->assertSame([false, false], $templates->pluck('approved')->all());
            $this->assertCount(1, $templates[0]['geometry']['holes']);
            $this->assertSame([], $templates[1]['geometry']['holes']);

            return true;
        });
        $this->get('/guide')->assertOk()->assertSee('قالب مع شريحة صغيرة')->assertSee('قالب بدون شريحة')->assertDontSee('شريحة كبيرة');
        $this->get('/products/'.Product::firstOrFail()->slug)->assertOk()->assertSee('بانتظار اعتماد المطبعة')->assertViewHas('templates', fn (Collection $templates): bool => $templates->pluck('kind')->all() === ['small_chip', 'no_chip']);
    }

    public function test_catalog_offers_the_latest_approved_version_once_and_omits_legacy_chip_types(): void
    {
        $this->store();
        $small = $this->version('small_chip');
        $small->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);
        $approved = $small->replicate();
        $approved->version = 2;
        $approved->save();
        $draft = $small->replicate();
        $draft->version = 3;
        $draft->approved_at = null;
        $draft->save();
        $legacy = CardTemplate::create(['name' => 'شريحة كبيرة', 'side' => 'front', 'active' => true]);
        $legacyVersion = $small->replicate();
        $legacyVersion->card_template_id = $legacy->id;
        $legacyVersion->save();

        $this->get('/editor')->assertOk()->assertViewHas('templates', fn (Collection $templates): bool => $templates->pluck('id')->all() === [$approved->id, $this->version('no_chip')->id]);
        $this->postJson('/api/products/'.Product::firstOrFail()->id.'/cart', ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 1, 'front_template_id' => $legacyVersion->id])->assertUnprocessable()->assertJsonValidationErrors('availability');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertModelExists($legacyVersion);
    }

    public function test_draft_no_chip_can_be_saved_and_reopened_but_cannot_be_ordered(): void
    {
        $this->store();
        $version = $this->version('no_chip');
        $id = (string) Str::uuid();
        $data = $this->designData($version->id);

        $this->putJson('/api/designs/'.$id, $data)->assertOk()->assertJsonPath('revision', 1);
        $this->get('/editor/'.$id)->assertOk()->assertViewHas('design', fn (Design $design): bool => $design->document === $data['document']);
        $this->postJson('/api/quote', $data)->assertUnprocessable()->assertJsonValidationErrors('availability');
        $this->postJson('/api/cart', ['design_id' => $id, 'revision' => 1, 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('availability');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame($data['document'], Design::findOrFail($id)->document);
        $this->assertNull($version->fresh()->approved_at);
    }

    public function test_approved_no_chip_is_priceable_and_cart_versions_keep_the_selected_geometry(): void
    {
        $this->store();
        $version = $this->version('no_chip');
        $version->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);
        $product = Product::firstOrFail();
        $input = ['request_id' => (string) Str::uuid(), 'mode' => 'single', 'quantity' => 2, 'front_template_id' => $version->id];

        $this->postJson('/api/products/'.$product->id.'/quote', $input)->assertOk()->assertJsonPath('subtotal_iqd', 24000)->assertJsonPath('preview.templates.front.geometry.holes', []);
        $this->postJson('/api/products/'.$product->id.'/cart', $input)->assertOk();
        $item = CartItem::firstOrFail();
        $this->assertSame($version->id, $item->version->document['sides']['front']['template_version_id']);
        $replacement = $version->replicate();
        $replacement->version = 2;
        $replacement->geometry = [...$version->geometry, 'width' => 84];
        $replacement->save();
        $this->assertSame(85.6, $item->fresh()->version->templates['front']['geometry']['width']);
        $this->assertSame([], $item->version->templates['front']['geometry']['holes']);
    }

    public function test_archived_template_remains_available_only_as_geometry_of_the_owned_saved_design(): void
    {
        $this->store();
        $legacy = CardTemplate::create(['name' => 'شريحة كبيرة مؤرشفة', 'side' => 'front', 'active' => false]);
        $version = $this->version('small_chip')->replicate();
        $version->card_template_id = $legacy->id;
        $version->save();
        $id = (string) Str::uuid();
        $this->putJson('/api/designs/'.$id, $this->designData($version->id))->assertOk();

        $this->get('/editor/'.$id)->assertOk()
            ->assertViewHas('templates', fn (Collection $templates): bool => ! $templates->contains('id', $version->id))
            ->assertViewHas('historicalTemplates', fn (Collection $templates): bool => $templates->pluck('id')->all() === [$version->id] && $templates->first()['geometry'] === $version->geometry);
    }

    #[TestWith(['no_chip', false])]
    #[TestWith(['small_chip', true])]
    public function test_admin_rejects_chip_type_and_geometry_mismatch_with_422(string $kind, bool $emptyHoles): void
    {
        $this->store();
        $version = $this->version('small_chip');
        $geometry = [...$version->geometry, ...($emptyHoles ? ['holes' => []] : [])];

        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/templates', ['name' => 'قالب جديد', 'side' => 'front', 'kind' => $kind, 'geometry' => json_encode($geometry), 'compatibility' => json_encode($version->compatibility)])
            ->assertUnprocessable()->assertJsonValidationErrors('geometry')->assertJsonPath('errors.geometry.0', 'فتحات القالب لا تطابق خيار الشريحة المختار.');
        $this->assertDatabaseCount('card_templates', 3);
        $this->assertDatabaseCount('template_versions', 3);
    }

    public function test_admin_cannot_reclassify_an_existing_small_chip_template(): void
    {
        $this->store();
        $version = $this->version('small_chip');

        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/templates', ['template_id' => $version->card_template_id, 'name' => 'تغيير النوع', 'side' => 'front', 'kind' => 'no_chip', 'geometry' => json_encode([...$version->geometry, 'holes' => []]), 'compatibility' => json_encode($version->compatibility)])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->assertSame('small_chip', $version->template->fresh()->kind);
        $this->assertDatabaseCount('template_versions', 3);
    }

    public function test_admin_can_add_a_no_chip_version_with_explicit_sample_approval_without_rewriting_the_draft(): void
    {
        $this->store();
        $draft = $this->version('no_chip');
        $admin = User::factory()->create(['role' => 'admin']);
        $input = ['template_id' => $draft->card_template_id, 'name' => 'قالب بدون شريحة', 'side' => 'front', 'kind' => 'no_chip', 'active' => 1, 'geometry' => json_encode($draft->geometry), 'compatibility' => json_encode($draft->compatibility), 'physical_approval' => 1];
        $this->actingAs($admin)->postJson('/admin/templates', $input)->assertUnprocessable()->assertJsonValidationErrors('sample_reference');

        $this->post('/admin/templates', [...$input, 'sample_reference' => 'AUTOMATED FIXTURE ONLY'])->assertRedirect('/admin/templates');
        $this->assertDatabaseHas('template_versions', ['card_template_id' => $draft->card_template_id, 'version' => 2, 'approved_by' => $admin->id, 'sample_reference' => 'AUTOMATED FIXTURE ONLY']);
        $this->assertNull($draft->fresh()->approved_at);
        $this->assertSame([], $draft->geometry['holes']);
    }

    public function test_admin_can_save_a_draft_without_claiming_physical_approval(): void
    {
        $this->store();
        $draft = $this->version('no_chip');

        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/templates', ['template_id' => $draft->card_template_id, 'name' => 'قالب بدون شريحة', 'side' => 'front', 'kind' => 'no_chip', 'active' => 1, 'geometry' => json_encode($draft->geometry), 'compatibility' => json_encode($draft->compatibility)])->assertRedirect('/admin/templates');

        $this->assertDatabaseHas('template_versions', ['card_template_id' => $draft->card_template_id, 'version' => 2, 'approved_at' => null, 'approved_by' => null, 'sample_reference' => null]);
    }

    public function test_upgrade_preserves_legacy_versions_and_saved_designs_while_adding_an_unapproved_no_chip_template(): void
    {
        $this->store();
        $small = $this->version('small_chip');
        $small->template->update(['name' => 'شريحة صغيرة — تجريبي']);
        $small->update(['approved_at' => now(), 'sample_reference' => 'EXISTING APPROVAL', 'geometry' => [...$small->geometry, 'width' => 84, 'bleed' => 1.5]]);
        $oldNoChip = $this->version('no_chip');
        $oldNoChip->delete();
        $oldNoChip->template->delete();
        $large = CardTemplate::create(['name' => 'شريحة كبيرة — تجريبي', 'side' => 'front', 'active' => true]);
        $largeVersion = $small->replicate();
        $largeVersion->card_template_id = $large->id;
        $largeVersion->save();
        $id = (string) Str::uuid();
        $data = $this->designData($largeVersion->id);
        $this->putJson('/api/designs/'.$id, $data)->assertOk();
        $originals = DB::table('template_versions')->get()->map(fn ($version): array => (array) $version);
        $migration = require database_path('migrations/2026_10_24_155514_add_kind_to_card_templates.php');
        $migration->down();

        $migration->up();

        $this->assertFalse($large->fresh()->active);
        foreach ($originals as $original) {
            $this->assertDatabaseHas('template_versions', $original);
        }
        $noChip = $this->version('no_chip');
        $this->assertNull($noChip->approved_at);
        $this->assertNull($noChip->sample_reference);
        $this->assertSame([...$small->geometry, 'holes' => []], $noChip->geometry);
        $this->assertSame($small->compatibility, $noChip->compatibility);
        $this->assertSame($data['document'], Design::findOrFail($id)->document);
        $this->get('/editor/'.$id)->assertOk()->assertViewHas('historicalTemplates', fn (Collection $templates): bool => $templates->pluck('id')->all() === [$largeVersion->id]);
    }
}
