<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use ZipArchive;

class CatalogReleaseInstallTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{archive: string, url: string, sha256: string, image_hash: string} */
    private function package(string $mode = 'valid'): array
    {
        $source = Storage::fake('release-fixture');
        Storage::fake('local');
        Http::preventStrayRequests();
        $upload = UploadedFile::fake()->image('design.png', 1100, 700);
        $image = file_get_contents($upload->getRealPath());
        $name = $mode === 'traversal' ? '../escape.png' : 'originals/101.png';
        $manifest = ['source' => 'release-fixture', 'license_confirmed' => true, 'products' => [['external_id' => '101', 'handle' => 'release-design', 'name' => 'Release design', 'file' => $name, 'active' => true, 'categories' => [['slug' => 'art', 'name' => 'فن']], 'metadata' => ['sha256' => hash('sha256', $image)]]]];
        $path = $source->path('catalog.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->addFromString($name, $image);
        if ($mode === 'symlink') {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        $zip->close();
        $url = 'https://example.test/catalog.zip';
        $sha = hash_file('sha256', $path);
        config(['catalog.release' => ['url' => $url, 'sha256' => $sha, 'products' => 1, 'unpacked_limit' => $mode === 'oversize' ? 10 : 10 * 1024 * 1024]]);

        return ['archive' => $path, 'url' => $url, 'sha256' => $sha, 'image_hash' => hash('sha256', $image)];
    }

    public function test_download_verifies_and_imports_the_release_and_reuses_the_cache_without_duplicates(): void
    {
        $package = $this->package();
        Http::fake([$package['url'] => Http::response(file_get_contents($package['archive']))]);

        $this->artisan('sticker:catalog-install')->assertSuccessful();
        $product = Product::firstOrFail();
        $this->artisan('sticker:catalog-install')->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('catalog_assets', 1);
        $this->assertSame('101', $product->catalog_source_id);
        Storage::disk('local')->assertExists($product->catalogAsset->path);
        Http::assertSentCount(1);
    }

    public function test_a_local_package_can_be_installed_without_any_network_request(): void
    {
        $package = $this->package();
        Http::fake();

        $this->artisan('sticker:catalog-install', ['--archive' => $package['archive']])->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
        Http::assertNothingSent();
    }

    public function test_checksum_failure_prevents_import(): void
    {
        $package = $this->package();
        file_put_contents($package['archive'], 'modified', FILE_APPEND);

        $this->artisan('sticker:catalog-install', ['--archive' => $package['archive']])->assertFailed();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('catalog_assets', 0);
    }

    #[TestWith(['traversal'])]
    #[TestWith(['symlink'])]
    #[TestWith(['oversize'])]
    public function test_unsafe_archive_members_or_size_are_rejected_before_extraction(string $mode): void
    {
        $package = $this->package($mode);

        $this->artisan('sticker:catalog-install', ['--archive' => $package['archive']])->assertFailed();

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_changed_cached_image_is_restored_from_the_verified_package(): void
    {
        $package = $this->package();
        $this->artisan('sticker:catalog-install', ['--archive' => $package['archive']])->assertSuccessful();
        $path = Storage::disk('local')->path('catalog-imports/'.substr($package['sha256'], 0, 16).'/source/originals/101.png');
        file_put_contents($path, 'damaged cache');

        $this->artisan('sticker:catalog-install', ['--archive' => $package['archive']])->assertSuccessful();

        $this->assertSame($package['image_hash'], hash_file('sha256', $path));
        $this->assertDatabaseCount('products', 1);
    }

    public function test_a_failed_download_does_not_leave_a_partial_archive_or_import_products(): void
    {
        $package = $this->package();
        Http::fake([$package['url'] => Http::response('Unavailable', 503)]);

        $this->artisan('sticker:catalog-install')->assertFailed();

        $this->assertDatabaseCount('products', 0);
        Storage::disk('local')->assertMissing('catalog-imports/'.substr($package['sha256'], 0, 16).'/package.zip.part');
        Http::assertSentCount(1);
    }

    public function test_missing_release_configuration_cannot_import_products(): void
    {
        config(['catalog.release' => null]);

        $this->artisan('sticker:catalog-install')->assertFailed();

        $this->assertDatabaseCount('products', 0);
    }
}
