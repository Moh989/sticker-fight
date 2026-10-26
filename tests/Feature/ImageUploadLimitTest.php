<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ImageUploadLimitTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([13312])]
    #[TestWith([14336])]
    public function test_personal_images_up_to_fourteen_megabytes_keep_their_full_dimensions(int $kilobytes): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->image('portrait.png', 400, 800)->size($kilobytes);

        $response = $this->post('/api/assets', ['file' => $image, 'rights' => true], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('width', 400)->assertJsonPath('height', 800);
        $asset = Asset::findOrFail($response->json('id'));
        $this->assertSame('upload', $asset->source);
        $this->assertSame(400, $asset->width);
        $this->assertSame(800, $asset->height);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_images_above_fourteen_megabytes_are_rejected_without_storing_an_asset(): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->image('large.png', 400, 800)->size(14337);

        $this->post('/api/assets', ['file' => $image, 'rights' => true], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', 'الحد الأعلى للصورة 14 ميغابايت.');

        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_admin_catalog_images_up_to_four_megabytes_are_published_with_their_original_dimensions(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $image = UploadedFile::fake()->image('catalog.png', 1200, 760)->size(4096);

        $response = $this->post('/admin/manage/products', ['name' => 'Four megabyte design', 'slug' => 'four-megabyte-design', 'active' => 1, 'art_file' => $image]);

        $product = Product::firstOrFail();
        $response->assertRedirect('/admin/manage/products/'.$product->id)->assertSessionHasNoErrors();
        $this->assertSame(1200, $product->catalogAsset->width);
        $this->assertSame(760, $product->catalogAsset->height);
        Storage::disk('local')->assertExists($product->catalogAsset->path);
        $this->get('/admin/manage/products/'.$product->id)->assertOk()->assertSee('data-max-bytes="4194304"', false)->assertSee('JPG · PNG · WebP / حتى 4 MB');
    }

    public function test_admin_catalog_images_above_four_megabytes_return_422_without_creating_a_product_or_asset(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $image = UploadedFile::fake()->image('large-catalog.png', 1200, 760)->size(4097);

        $this->post('/admin/manage/products', ['name' => 'Too large', 'slug' => 'too-large', 'active' => 1, 'art_file' => $image], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('art_file')
            ->assertJsonPath('errors.art_file.0', 'الحد الأعلى لصورة الكتالوج 4 ميغابايت.');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('catalog_assets', 0);
        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_oversized_catalog_replacements_return_422_and_preserve_the_existing_product(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $product = Product::create(['name' => 'Original design', 'slug' => 'original-design', 'art_key' => 'minimal', 'price_iqd' => 8000, 'active' => true]);
        $image = UploadedFile::fake()->image('replacement.png', 1200, 760)->size(4097);

        $this->post('/admin/manage/products/'.$product->id, ['name' => 'Changed design', 'slug' => $product->slug, 'price_iqd' => 9000, 'active' => 1, 'art_file' => $image], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('art_file');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Original design', 'price_iqd' => 8000, 'art_key' => 'minimal', 'catalog_asset_id' => null]);
        $this->assertDatabaseCount('catalog_assets', 0);
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_catalog_files_rejected_by_php_return_422_without_silently_saving_other_product_changes(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $product = Product::create(['name' => 'Original design', 'slug' => 'original-design', 'art_key' => 'minimal', 'active' => true]);
        $source = UploadedFile::fake()->image('catalog.png', 1200, 760);
        $image = new UploadedFile($source->getRealPath(), 'catalog.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);

        $this->post('/admin/manage/products/'.$product->id, ['name' => 'Changed design', 'slug' => $product->slug, 'active' => 1, 'art_file' => $image], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('art_file')
            ->assertJsonPath('errors.art_file.0', 'تعذر رفع صورة التصميم. أعد المحاولة بملف حتى 4 ميغابايت.');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Original design', 'art_key' => 'minimal', 'catalog_asset_id' => null]);
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('catalog_assets', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
