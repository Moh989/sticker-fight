<?php

namespace Tests\Feature;

use App\Models\CatalogAsset;
use App\Models\Category;
use App\Models\DeviceModel;
use App\Models\Product;
use App\Models\ProductSection;
use App\Services\CatalogSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use ZipArchive;

class CatalogSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{archive: string, product: Product, image_hash: string} */
    private function package(): array
    {
        Storage::fake('local');
        $source = Storage::fake('snapshot-fixture');
        $category = Category::create(['name' => 'تصاميم باسمك', 'slug' => 'your-name', 'active' => true]);
        $extra = Category::create(['name' => 'عراقي', 'slug' => 'iraqi', 'active' => true]);
        $section = ProductSection::factory()->create();
        $model = DeviceModel::factory()->for($section, 'section')->create();
        $id = (string) Str::uuid();
        $upload = UploadedFile::fake()->image('card.png', 856, 540);
        $image = file_get_contents($upload->getRealPath());
        Storage::disk('local')->put('catalog/'.$id.'/original.png', $image);
        Storage::disk('local')->put('catalog/'.$id.'/thumbnail.webp', 'example-thumbnail');
        $asset = CatalogAsset::create(['id' => $id, 'path' => 'catalog/'.$id.'/original.png', 'thumbnail_path' => 'catalog/'.$id.'/thumbnail.webp', 'width' => 856, 'height' => 540, 'sha256' => hash('sha256', $image)]);
        $product = Product::create(['name' => 'اسمك على البطاقة', 'slug' => 'your-name-card', 'category_id' => $category->id, 'product_section_id' => $section->id, 'catalog_asset_id' => $asset->id, 'price_iqd' => 3000, 'active' => true, 'is_featured' => true, 'featured_order' => 2, 'popularity_count' => 19, 'personalization_enabled' => true, 'personalization_layout' => ['x' => 12, 'y' => 38, 'width' => 61.6, 'height' => 12, 'font_size' => 4.5, 'color' => '#FFFFFF'], 'catalog_metadata' => ['private_order_id' => 'must-not-publish']]);
        $product->categories()->attach([$category->id, $extra->id]);
        $product->deviceModels()->attach($model);
        $archive = $source->path('catalog.zip');
        app(CatalogSnapshot::class)->export($archive);
        config(['catalog.snapshot' => ['sha256' => hash_file('sha256', $archive), 'url' => 'https://example.test/newdes-catalog.zip', 'unpacked_limit' => 10 * 1024 * 1024]]);

        return ['archive' => $archive, 'product' => $product, 'image_hash' => $asset->sha256];
    }

    public function test_export_includes_catalog_only_and_excludes_demo_deleted_and_private_data(): void
    {
        $fixture = $this->package();
        Storage::disk('local')->put('design-fonts/private.woff2', 'private-font');
        Storage::disk('local')->put('assets/private-customer/photo.png', 'customer-photo');
        Product::create(['name' => 'Demo', 'slug' => 'demo', 'is_demo' => true]);
        $deleted = Product::create(['name' => 'Deleted', 'slug' => 'deleted']);
        $deleted->delete();
        $path = Storage::disk('snapshot-fixture')->path('clean.zip');

        $this->artisan('sticker:catalog-export', ['archive' => $path])->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open($path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertCount(1, $manifest['products']);
        $this->assertSame('your-name-card', $manifest['products'][0]['slug']);
        $this->assertSame(3, $zip->numFiles);
        $this->assertArrayNotHasKey('catalog_metadata', $manifest['products'][0]);
        $this->assertArrayNotHasKey('popularity_count', $manifest['products'][0]);
        $this->assertArrayNotHasKey('users', $manifest);
        $this->assertFalse($zip->locateName('design-fonts/private.woff2'));
        $this->assertFalse($zip->locateName('assets/private-customer/photo.png'));
        $zip->close();
        Storage::disk('local')->assertExists($fixture['product']->catalogAsset->path);
    }

    public function test_restore_preserves_images_categories_device_compatibility_name_layout_and_featured_order_without_double_counting(): void
    {
        $fixture = $this->package();
        $oldId = $fixture['product']->id;
        $fixture['product']->forceDelete();
        CatalogAsset::query()->delete();
        Category::query()->delete();
        DeviceModel::query()->delete();
        ProductSection::query()->delete();
        Http::preventStrayRequests();

        $this->artisan('sticker:catalog-restore', ['--archive' => $fixture['archive']])->assertSuccessful();

        $product = Product::firstOrFail();
        $this->assertNotSame($oldId, $product->id);
        $this->assertSame(3000, $product->price_iqd);
        $this->assertTrue($product->is_featured);
        $this->assertSame(2, $product->featured_order);
        $this->assertTrue($product->personalization_enabled);
        $this->assertSame(38, $product->personalization_layout['y']);
        $this->assertSame(0, $product->popularity_count);
        $this->assertSame($fixture['image_hash'], hash_file('sha256', Storage::disk('local')->path($product->catalogAsset->path)));
        $this->assertEqualsCanonicalizing(['iraqi', 'your-name'], $product->categories->pluck('slug')->all());
        $this->assertCount(1, $product->deviceModels);
        $this->assertSame($product->product_section_id, $product->deviceModels->first()->product_section_id);
        $product->update(['name' => 'Owner edit', 'price_iqd' => 7000]);
        $this->artisan('sticker:catalog-restore', ['--archive' => $fixture['archive']])->assertSuccessful();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('catalog_assets', 1);
        $this->assertSame('Owner edit', $product->fresh()->name);
        $this->assertSame(7000, $product->fresh()->price_iqd);
        $product->delete();
        $this->artisan('sticker:catalog-restore', ['--archive' => $fixture['archive']])->assertSuccessful();
        $this->assertSoftDeleted($product);
        Http::assertNothingSent();
    }

    public function test_restore_replaces_seeded_demo_artwork_with_the_real_product(): void
    {
        $fixture = $this->package();
        $fixture['product']->update(['is_demo' => true, 'name' => 'Demo artwork']);
        $unusedDemo = Product::create(['name' => 'Unused demo', 'slug' => 'unused-demo', 'is_demo' => true, 'active' => true]);

        $this->artisan('sticker:catalog-restore', ['--archive' => $fixture['archive']])->assertSuccessful();

        $this->assertFalse($fixture['product']->fresh()->is_demo);
        $this->assertSame('اسمك على البطاقة', $fixture['product']->fresh()->name);
        $this->assertDatabaseCount('products', 2);
        $this->assertFalse($unusedDemo->fresh()->active);
    }

    public function test_restore_preserves_a_renamed_product_matched_by_its_source_id(): void
    {
        $fixture = $this->package();
        $fixture['product']->update(['catalog_source' => 'owner-library', 'catalog_source_id' => '101']);
        $archive = Storage::disk('snapshot-fixture')->path('with-source.zip');
        app(CatalogSnapshot::class)->export($archive);
        config(['catalog.snapshot.sha256' => hash_file('sha256', $archive)]);
        $fixture['product']->update(['slug' => 'owner-renamed', 'name' => 'Owner title']);

        $this->artisan('sticker:catalog-restore', ['--archive' => $archive])->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
        $this->assertSame('Owner title', $fixture['product']->fresh()->name);
        $this->assertSame('owner-renamed', $fixture['product']->fresh()->slug);
    }

    #[TestWith(['checksum'])]
    #[TestWith(['traversal'])]
    #[TestWith(['symlink'])]
    #[TestWith(['missing'])]
    #[TestWith(['image_hash'])]
    #[TestWith(['unknown_field'])]
    #[TestWith(['oversize'])]
    #[TestWith(['hierarchy_cycle'])]
    #[TestWith(['missing_reference'])]
    public function test_invalid_archives_do_not_commit_database_changes_or_leave_extracted_files(string $mode): void
    {
        $fixture = $this->package();
        $fixture['product']->forceDelete();
        CatalogAsset::query()->delete();
        Category::query()->delete();
        Storage::disk('local')->deleteDirectory('catalog');
        if ($mode === 'checksum') {
            file_put_contents($fixture['archive'], 'modified', FILE_APPEND);
        } elseif ($mode === 'oversize') {
            config(['catalog.snapshot.unpacked_limit' => 1]);
        } else {
            $zip = new ZipArchive;
            $zip->open($fixture['archive']);
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $path = $manifest['assets'][0]['path'];
            match ($mode) {
                'traversal' => $zip->addFromString('../escape.png', 'escape'),
                'symlink' => $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0120777 << 16),
                'missing' => $zip->deleteName($path),
                'image_hash' => $manifest['files'][$path]['sha256'] = str_repeat('a', 64),
                'unknown_field' => $manifest['products'][0]['popularity_count'] = 1000,
                'hierarchy_cycle' => $manifest['sections'][0]['parent'] = $manifest['sections'][0]['slug'],
                'missing_reference' => $manifest['products'][0]['categories'][] = 'missing-category',
            };
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $zip->close();
            config(['catalog.snapshot.sha256' => hash_file('sha256', $fixture['archive'])]);
        }

        $this->artisan('sticker:catalog-restore', ['--archive' => $fixture['archive']])->assertFailed();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('catalog_assets', 0);
        $this->assertDatabaseCount('categories', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_downloads_configured_release_and_reuses_verified_archive(): void
    {
        $fixture = $this->package();
        Http::preventStrayRequests();
        Http::fake(['https://example.test/newdes-catalog.zip' => Http::response(file_get_contents($fixture['archive']))]);

        $this->artisan('sticker:catalog-restore')->assertSuccessful();
        $this->artisan('sticker:catalog-restore')->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
        Http::assertSentCount(1);
    }

    public function test_failed_download_leaves_no_partial_package_and_preserves_existing_products(): void
    {
        $this->package();
        Http::preventStrayRequests();
        Http::fake(['https://example.test/newdes-catalog.zip' => Http::response('Unavailable', 503)]);

        $this->artisan('sticker:catalog-restore')->assertFailed();

        $this->assertDatabaseCount('products', 1);
        $this->assertSame([], Storage::disk('local')->allFiles('catalog-imports'));
        Http::assertSentCount(1);
    }
}
