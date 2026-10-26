<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LicensedCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private function image(): string
    {
        $image = imagecreatetruecolor(1300, 840);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 50, 40, 1249, 799, imagecolorallocatealpha($image, 30, 80, 100, 0));
        imagefilledrectangle($image, 100, 100, 199, 199, imagecolorallocatealpha($image, 0, 0, 0, 0));
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function manifest(array $overrides = [], array $rowOverrides = []): string
    {
        $source = Storage::fake('licensed-source');
        Storage::fake('local');
        $source->put('source.png', $this->image());
        $row = array_replace(['external_id' => '101', 'handle' => 'cloud', 'name' => 'Cloud', 'file' => 'source.png', 'active' => true, 'categories' => [['slug' => 'artistic', 'name' => 'فن'], ['slug' => 'nature', 'name' => 'طبيعة']], 'metadata' => ['source_url' => 'https://example.test/products/cloud']], $rowOverrides);
        $source->put('manifest.json', json_encode(array_replace(['source' => 'licensed-fixture', 'license_confirmed' => true, 'products' => [$row]], $overrides), JSON_THROW_ON_ERROR));

        return $source->path('manifest.json');
    }

    public function test_import_keeps_original_files_trims_only_transparent_padding_and_publishes_under_every_category(): void
    {
        $path = $this->manifest(rowOverrides: ['price_iqd' => 1, 'popularity_count' => 999, 'is_featured' => true]);
        $hash = hash_file('sha256', dirname($path).'/source.png');
        StoreSettings::put('price_single_iqd', 12000);

        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertSuccessful();

        $product = Product::firstOrFail();
        $this->assertSame('licensed-fixture', $product->catalog_source);
        $this->assertSame('101', $product->catalog_source_id);
        $this->assertSame(2, $product->categories()->count());
        $this->assertSame(1200, $product->catalogAsset->width);
        $this->assertSame(760, $product->catalogAsset->height);
        $normalized = imagecreatefrompng(Storage::disk('local')->path($product->catalogAsset->path));
        $black = imagecolorat($normalized, 50, 60);
        $this->assertSame(0, $black >> 24 & 0x7F);
        $this->assertSame(0, $black & 0xFFFFFF);
        $this->assertSame($hash, hash_file('sha256', dirname($path).'/source.png'));
        $this->assertNull($product->price_iqd);
        $this->assertSame(12000, $product->base_price_iqd);
        $this->assertFalse($product->is_featured);
        $this->assertSame(0, $product->popularity_count);
        $this->assertDatabaseCount('guest_sessions', 0);
        $this->get('/designs?category=artistic')->assertOk()->assertSee('Cloud');
        $this->get('/designs?category=nature')->assertOk()->assertSee('Cloud');
        $this->get('/designs?bestsellers=1')->assertOk()->assertDontSee('Cloud');
        $this->assertDatabaseCount('assets', 0);
        Storage::disk('local')->assertExists($product->catalogAsset->path);
    }

    public function test_repeated_source_id_preserves_owner_changes_and_does_not_create_more_products(): void
    {
        $path = $this->manifest();
        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertSuccessful();
        $product = Product::firstOrFail();
        $product->update(['name' => 'Owner title', 'price_iqd' => 8500, 'active' => false]);
        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertSuccessful();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('catalog_assets', 1);
        $this->assertSame('Owner title', $product->fresh()->name);
        $this->assertSame(8500, $product->fresh()->price_iqd);
        $this->assertFalse($product->fresh()->active);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_legacy_source_filenames_link_existing_designs_without_resetting_their_price_or_name(): void
    {
        $path = $this->manifest(rowOverrides: ['handle' => 'cc-cloud']);
        $source = Storage::disk('licensed-source');
        $source->put('Artistic/01_cc-cloud-card-cover.png', $this->image());
        $this->artisan('sticker:catalog-import', ['directory' => $source->path('')])->assertSuccessful();
        $old = Product::firstOrFail();
        $old->update(['name' => 'My edited title', 'price_iqd' => 9000]);
        $imageId = $old->catalog_asset_id;
        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertSuccessful();
        $this->assertDatabaseCount('products', 1);
        $this->assertSame($imageId, $old->fresh()->catalog_asset_id);
        $this->assertSame('101', $old->fresh()->catalog_source_id);
        $this->assertSame('My edited title', $old->fresh()->name);
        $this->assertSame(9000, $old->fresh()->price_iqd);
    }

    public function test_deleted_designs_stay_deleted_when_linked_to_their_source(): void
    {
        $path = $this->manifest();
        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertSuccessful();
        $product = Product::firstOrFail();
        $product->delete();
        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertSuccessful();
        $this->assertSoftDeleted($product);
        $this->assertSame(0, Product::count());
    }

    public function test_source_coverage_review_stays_unpublished(): void
    {
        $path = $this->manifest(rowOverrides: ['active' => false, 'metadata' => ['review_reason' => 'Only a partial source is available.']]);
        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertSuccessful();
        $product = Product::firstOrFail();
        $this->assertFalse($product->active);
        $this->get('/products/'.$product->slug)->assertNotFound();
        $this->assertSame('Only a partial source is available.', $product->catalog_metadata['review_reason']);
        Product::create(['name' => 'Normal design', 'slug' => 'normal-design', 'art_key' => 'minimal']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/manage/products?source_review=1')->assertOk()->assertSee('Cloud')->assertDontSee('Normal design');
    }

    public function test_missing_or_escaping_images_fail_without_leaving_assets_or_products(): void
    {
        $path = $this->manifest(rowOverrides: ['file' => '../outside.png']);
        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertFailed();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('guest_sessions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_owner_authorization_is_required_before_import(): void
    {
        $path = $this->manifest(['license_confirmed' => false]);
        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertFailed();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('guest_sessions', 0);
    }

    public function test_unrelated_uuid_audit_records_cannot_link_another_product(): void
    {
        $path = $this->manifest();
        $other = Product::create(['name' => 'Different design', 'slug' => 'different-design', 'art_key' => 'minimal']);
        AuditLog::create(['action' => 'catalog.imported', 'subject_type' => 'Asset', 'subject_id' => $other->id.'-asset-uuid', 'changes' => ['file' => '01_cloud.png']]);

        $this->artisan('sticker:catalog-import-licensed', ['manifest' => $path])->assertSuccessful();

        $this->assertDatabaseCount('products', 2);
        $this->assertNull($other->fresh()->catalog_source_id);
        $this->assertNotSame($other->id, Product::where('catalog_source_id', '101')->firstOrFail()->id);
    }
}
