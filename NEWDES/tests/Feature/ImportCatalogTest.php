<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function imageBytes(int $width, int $height): string
    {
        $image = UploadedFile::fake()->image('image.png', $width, $height);

        return file_get_contents($image->getRealPath());
    }

    public function test_import_publishes_private_images_under_categories_with_general_prices(): void
    {
        $disk = Storage::fake('catalog-source');
        Storage::fake('local');
        $image = UploadedFile::fake()->image('01_cloud.png', 1200, 760);
        $disk->put('CUCU_03_Anime/01_cloud.png', file_get_contents($image->getRealPath()));
        $disk->put('CUCU_13_Animals/02_cloud.png', $this->imageBytes(1100, 700));
        $category = Category::create(['name' => 'حيواناتي', 'slug' => 'animals']);
        StoreSettings::put('price_single_iqd', 12000);

        $this->artisan('sticker:catalog-import', ['directory' => $disk->path('')])->assertSuccessful();

        $this->assertDatabaseCount('products', 2);
        $product = Product::where('slug', 'anime-cloud')->firstOrFail();
        $this->assertSame('Cloud', $product->name);
        $this->assertNull($product->price_iqd);
        $this->assertSame(12000, $product->base_price_iqd);
        $this->assertTrue($product->active);
        $this->assertSame('حيواناتي', $category->fresh()->name);
        $this->assertSame($category->id, Product::where('slug', 'animals-cloud')->value('category_id'));
        Storage::disk('local')->assertExists($product->catalogAsset->path);
        Storage::disk('local')->assertExists($product->catalogAsset->thumbnail_path);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('guest_sessions', 0);
    }

    public function test_reimport_skips_identical_art_without_resetting_owner_changes(): void
    {
        $disk = Storage::fake('catalog-source');
        Storage::fake('local');
        $bytes = $this->imageBytes(1000, 630);
        $disk->put('Artistic/01_cloud.png', $bytes);
        $this->artisan('sticker:catalog-import', ['directory' => $disk->path('')])->assertSuccessful();
        $product = Product::firstOrFail();
        $product->update(['name' => 'My title', 'price_iqd' => 8500, 'active' => false]);
        $disk->put('Artistic/02_duplicate.png', $bytes);

        $this->artisan('sticker:catalog-import', ['directory' => $disk->path('')])->expectsOutput('Created: 0; skipped existing: 2; failed: 0.')->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('catalog_assets', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'My title', 'price_iqd' => 8500, 'active' => false]);
        Storage::disk('local')->assertExists($product->catalogAsset->path);
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('guest_sessions', 0);
    }

    public function test_invalid_image_fails_without_publishing_or_leaving_upload_records(): void
    {
        $disk = Storage::fake('catalog-source');
        Storage::fake('local');
        $disk->put('Anime/01_invalid.png', '<script>invalid image</script>');

        $this->artisan('sticker:catalog-import', ['directory' => $disk->path('')])->assertFailed();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('catalog_assets', 0);
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('guest_sessions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reimport_does_not_restore_a_deleted_catalog_design(): void
    {
        $disk = Storage::fake('catalog-source');
        Storage::fake('local');
        $disk->put('Artistic/01_cloud.png', $this->imageBytes(1000, 630));
        $this->artisan('sticker:catalog-import', ['directory' => $disk->path('')])->assertSuccessful();
        $product = Product::firstOrFail();
        $product->delete();

        $this->artisan('sticker:catalog-import', ['directory' => $disk->path('')])->expectsOutput('Created: 0; skipped existing: 1; failed: 0.')->assertSuccessful();

        $this->assertSoftDeleted($product);
        $this->assertSame(0, Product::count());
        $this->assertDatabaseCount('products', 1);
        Storage::disk('local')->assertExists($product->catalogAsset->path);
    }

    public function test_different_images_with_the_same_name_get_distinct_product_urls(): void
    {
        $disk = Storage::fake('catalog-source');
        Storage::fake('local');
        $disk->put('Artistic/01_cloud.png', $this->imageBytes(1000, 630));
        $disk->put('Artistic/02_cloud.png', $this->imageBytes(1001, 631));

        $this->artisan('sticker:catalog-import', ['directory' => $disk->path('')])->assertSuccessful();

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['slug' => 'artistic-cloud']);
        $this->assertStringStartsWith('artistic-cloud-', Product::orderByDesc('id')->value('slug'));
        $this->assertDatabaseCount('guest_sessions', 0);
    }

    public function test_missing_directory_fails_without_writing_catalog_data(): void
    {
        $disk = Storage::fake('catalog-source');

        $this->artisan('sticker:catalog-import', ['directory' => $disk->path('does-not-exist')])->assertFailed();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('guest_sessions', 0);
    }
}
