<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\CatalogAsset;
use App\Models\Design;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): string
    {
        $response = $this->get('/editor')->assertOk();
        preg_match('/name="guest-workspace" content="([a-f0-9]{64})"/', $response->getContent(), $match);

        return $match[1] ?? '';
    }

    private function payload(): array
    {
        return ['name' => 'Private guest draft', 'revision' => 0, 'options' => ['mode' => 'single', 'printed_side' => 'front', 'transparent_side' => 'front', 'material_id' => null, 'transparent_material_id' => null, 'finish_id' => null], 'document' => ['version' => 1, 'sides' => ['front' => ['template_version_id' => null, 'background' => '#FF2D8D', 'elements' => []], 'back' => ['template_version_id' => null, 'background' => null, 'elements' => []]]]];
    }

    public function test_guest_workspace_persists_in_its_session_and_rotates_for_a_new_guest(): void
    {
        $first = $this->workspace();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertSame($first, $this->workspace());

        $this->post('/logout')->assertRedirect('/');

        $this->assertNotSame($first, $this->workspace());
        $this->assertDatabaseCount('guest_sessions', 2);
    }

    public function test_guest_cannot_adopt_another_workspace_using_query_parameters(): void
    {
        $first = $this->workspace();
        $firstGuest = session('guest_id');
        $this->post('/logout');

        $this->get('/editor?'.http_build_query(['guest_id' => $firstGuest, 'workspace' => $first]))
            ->assertOk()->assertDontSee('content="'.$first.'"', false);

        $this->assertNotSame($firstGuest, session('guest_id'));
    }

    public function test_new_visitor_button_starts_an_independent_session_without_deleting_previous_work(): void
    {
        $workspace = $this->workspace();
        $id = (string) Str::uuid();
        $this->withHeader('X-Guest-Workspace', $workspace)->putJson('/api/designs/'.$id, $this->payload())->assertOk();
        CartItem::create(['id' => (string) Str::uuid(), 'guest_session_id' => session('guest_id'), 'design_version_id' => Design::findOrFail($id)->versions()->value('id'), 'quantity' => 1]);
        $this->flushHeaders();

        $this->post('/logout', ['new_workspace' => 1])->assertRedirect('/editor')->assertSessionHas('success', 'بدأت مساحة زائر جديدة.');

        $this->assertNotSame($workspace, $this->workspace());
        $this->assertDatabaseHas('designs', ['id' => $id, 'name' => 'Private guest draft']);
        $this->get('/editor/'.$id)->assertNotFound();
        $this->get('/saved')->assertOk()->assertDontSee('Private guest draft');
        $this->get('/cart')->assertOk()->assertDontSee('Private guest draft');
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_stale_tab_cannot_save_or_upload_into_the_new_guests_workspace(): void
    {
        $first = $this->workspace();
        $this->post('/logout');
        $this->workspace();
        $id = (string) Str::uuid();

        $this->withHeader('X-Guest-Workspace', $first)->putJson('/api/designs/'.$id, $this->payload())
            ->assertConflict()->assertJsonPath('code', 'guest_workspace_changed');
        $this->postJson('/api/assets', [])->assertConflict()->assertJsonPath('code', 'guest_workspace_changed');

        $this->assertDatabaseCount('designs', 0);
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_current_guest_saves_and_the_next_guest_sees_an_empty_private_area(): void
    {
        $workspace = $this->workspace();
        $id = (string) Str::uuid();
        $this->withHeader('X-Guest-Workspace', $workspace)->putJson('/api/designs/'.$id, $this->payload())->assertOk();
        $owner = Design::findOrFail($id)->guest_session_id;
        $this->flushHeaders();
        $this->post('/logout');
        $this->workspace();

        $this->get('/saved')->assertOk()->assertDontSee('Private guest draft');
        $this->get('/cart')->assertOk()->assertDontSee('Private guest draft');
        $this->get('/editor/'.$id)->assertNotFound();
        $this->getJson('/api/designs/'.$id)->assertNotFound();
        $this->putJson('/api/designs/'.$id, $this->payload())->assertNotFound();

        $this->assertNotSame($owner, session('guest_id'));
        $this->assertSame(1, Design::findOrFail($id)->revision);
    }

    public function test_personalised_html_cannot_be_stored_in_a_shared_cache(): void
    {
        foreach (['/', '/designs', '/editor', '/saved', '/cart'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
    }

    public function test_public_catalog_images_do_not_create_or_set_a_guest_session(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('catalog/test/thumbnail.webp', 'public catalog fixture');
        $asset = CatalogAsset::create(['id' => (string) Str::uuid(), 'path' => 'catalog/test/original.png', 'thumbnail_path' => 'catalog/test/thumbnail.webp', 'width' => 1000, 'height' => 630, 'sha256' => hash('sha256', 'fixture')]);
        Product::create(['name' => 'Public catalog', 'slug' => 'public-catalog', 'catalog_asset_id' => $asset->id, 'active' => true]);

        $response = $this->get('/catalog/'.$asset->id)->assertOk();

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertDatabaseCount('guest_sessions', 0);
    }
}
