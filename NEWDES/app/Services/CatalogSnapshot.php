<?php

namespace App\Services;

use App\Models\CatalogAsset;
use App\Models\Category;
use App\Models\DeviceModel;
use App\Models\Product;
use App\Models\ProductSection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class CatalogSnapshot
{
    private const PRODUCT_FIELDS = ['name', 'slug', 'description', 'art_key', 'price_iqd', 'active', 'is_featured', 'featured_order', 'catalog_source', 'catalog_source_id', 'personalization_enabled', 'personalization_layout'];

    private const SECTION_FIELDS = ['name', 'slug', 'description', 'illustration', 'model_hint', 'requires_model', 'sort_order', 'active', 'audience'];

    /** @return array{products: int, categories: int, files: int, bytes: int, sha256: string} */
    public function export(string $archive): array
    {
        if (is_file($archive)) {
            throw new RuntimeException('The destination already exists. Choose a new archive name.');
        }
        File::ensureDirectoryExists(dirname($archive));
        $disk = Storage::disk('local');
        $products = Product::where('is_demo', false)->with(['category', 'categories', 'catalogAsset', 'section', 'deviceModels'])->orderBy('id')->get();
        $manifest = ['format' => 'sticker-fight/catalog-v1', 'categories' => Category::orderBy('id')->get()->map(fn (Category $category): array => $category->only(['name', 'slug', 'description', 'active']))->all(), 'sections' => ProductSection::with('parent')->orderBy('id')->get()->map(fn (ProductSection $section): array => $section->only(self::SECTION_FIELDS) + ['parent' => $section->parent?->slug])->all(), 'models' => DeviceModel::with('section')->orderBy('id')->get()->map(fn (DeviceModel $model): array => $model->only(['name', 'slug', 'manufacturer', 'active']) + ['section' => $model->section->slug])->all(), 'products' => [], 'assets' => [], 'files' => []];
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create the catalog archive.');
        }
        try {
            foreach ($products as $product) {
                $manifest['products'][] = $product->only(self::PRODUCT_FIELDS) + ['category' => $product->category?->slug, 'categories' => $product->categories->pluck('slug')->all(), 'section' => $product->section?->slug, 'models' => $product->deviceModels->pluck('slug')->all(), 'asset' => $product->catalog_asset_id];
                $asset = $product->catalogAsset;
                if (! $asset || isset($manifest['assets'][$asset->id])) {
                    continue;
                }
                $manifest['assets'][$asset->id] = $asset->only(['id', 'path', 'thumbnail_path', 'width', 'height', 'sha256']);
                foreach ([$asset->path, $asset->thumbnail_path] as $path) {
                    if (! preg_match('~^catalog/[a-f0-9-]{36}/(original\.png|thumbnail\.webp)$~D', $path) || ! $disk->exists($path) || is_link($disk->path($path))) {
                        throw new RuntimeException('A catalog image is missing or has an unsafe path.');
                    }
                    $manifest['files'][$path] = ['bytes' => $disk->size($path), 'sha256' => hash_file('sha256', $disk->path($path))];
                    if (! $zip->addFile($disk->path($path), $path)) {
                        throw new RuntimeException('Could not add a catalog image.');
                    }
                    $zip->setCompressionName($path, ZipArchive::CM_STORE);
                }
            }
            $manifest['assets'] = array_values($manifest['assets']);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new RuntimeException('Could not finish the catalog archive.');
            }
        } catch (\Throwable $exception) {
            unset($zip);
            File::delete($archive);
            throw $exception;
        }

        return ['products' => count($manifest['products']), 'categories' => count($manifest['categories']), 'files' => count($manifest['files']), 'bytes' => filesize($archive), 'sha256' => hash_file('sha256', $archive)];
    }

    /** @return array{created: int, skipped: int} */
    public function install(string $archive, string $checksum, int $unpackedLimit): array
    {
        if (! is_file($archive) || ! preg_match('/^[a-f0-9]{64}$/D', $checksum) || ! hash_equals($checksum, hash_file('sha256', $archive))) {
            throw new RuntimeException('Catalog SHA-256 verification failed.');
        }
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Invalid catalog archive.');
        }
        $staging = 'catalog-imports/snapshot-'.Str::uuid();
        $disk = Storage::disk('local');
        $createdDirectories = [];
        try {
            $manifest = $this->verifyArchive($zip, $unpackedLimit);
            foreach ($manifest['files'] as $name => $file) {
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Could not read a catalog image.');
                }
                try {
                    $disk->put($staging.'/'.$name, $stream);
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($file['sha256'], hash_file('sha256', $disk->path($staging.'/'.$name)))) {
                    throw new RuntimeException('A catalog image checksum does not match.');
                }
            }
            foreach ($manifest['assets'] as $asset) {
                $size = getimagesize($disk->path($staging.'/'.$asset['path']));
                if (! $size || $size[0] !== $asset['width'] || $size[1] !== $asset['height'] || $size['mime'] !== 'image/png' || ! hash_equals($asset['sha256'], $manifest['files'][$asset['path']]['sha256'])) {
                    throw new RuntimeException('Catalog image geometry does not match.');
                }
            }

            return DB::transaction(function () use ($manifest, $staging, $disk, &$createdDirectories): array {
                $categories = [];
                foreach ($manifest['categories'] as $row) {
                    $categories[$row['slug']] = Category::firstOrCreate(['slug' => $row['slug']], $row)->id;
                }
                $sections = [];
                foreach ($manifest['sections'] as $row) {
                    $sections[$row['slug']] = ProductSection::firstOrCreate(['slug' => $row['slug']], Arr::except($row, ['parent']))->id;
                }
                foreach ($manifest['sections'] as $row) {
                    if ($row['parent']) {
                        ProductSection::whereKey($sections[$row['slug']])->whereNull('parent_id')->update(['parent_id' => $sections[$row['parent']]]);
                    }
                }
                $models = [];
                foreach ($manifest['models'] as $row) {
                    $models[$row['slug']] = DeviceModel::firstOrCreate(['slug' => $row['slug']], Arr::except($row, ['section']) + ['product_section_id' => $sections[$row['section']]])->id;
                }
                $assets = collect($manifest['assets'])->keyBy('id');
                $counts = ['created' => 0, 'skipped' => 0];
                foreach ($manifest['products'] as $row) {
                    $product = Product::withTrashed()->where(function ($query) use ($row): void {
                        $query->where('slug', $row['slug']);
                        if ($row['catalog_source'] && $row['catalog_source_id']) {
                            $query->orWhere(fn ($source) => $source->where('catalog_source', $row['catalog_source'])->where('catalog_source_id', $row['catalog_source_id']));
                        }
                    })->first();
                    if ($product && (! $product->is_demo || $product->trashed())) {
                        $counts['skipped']++;

                        continue;
                    }
                    $assetId = null;
                    if ($row['asset']) {
                        $source = $assets[$row['asset']];
                        $asset = CatalogAsset::where('sha256', $source['sha256'])->first();
                        if (! $asset) {
                            $assetId = (string) Str::uuid();
                            $directory = 'catalog/'.$assetId;
                            $createdDirectories[] = $directory;
                            if (! $disk->copy($staging.'/'.$source['path'], $directory.'/original.png') || ! $disk->copy($staging.'/'.$source['thumbnail_path'], $directory.'/thumbnail.webp')) {
                                throw new RuntimeException('Could not save a catalog image.');
                            }
                            $asset = CatalogAsset::create(Arr::except($source, ['id', 'path', 'thumbnail_path']) + ['id' => $assetId, 'path' => $directory.'/original.png', 'thumbnail_path' => $directory.'/thumbnail.webp']);
                        }
                        $assetId = $asset->id;
                    }
                    $product ??= new Product;
                    $product->fill(Arr::only($row, self::PRODUCT_FIELDS) + ['category_id' => $row['category'] ? $categories[$row['category']] : null, 'product_section_id' => $row['section'] ? $sections[$row['section']] : null, 'catalog_asset_id' => $assetId, 'is_demo' => false, 'popularity_count' => 0])->save();
                    $product->categories()->sync(array_map(fn (string $slug): int => $categories[$slug], $row['categories']));
                    $product->deviceModels()->sync(array_map(fn (string $slug): int => $models[$slug], $row['models']));
                    $counts['created']++;
                }
                Product::where('is_demo', true)->update(['active' => false]);

                return $counts;
            });
        } catch (\Throwable $exception) {
            foreach ($createdDirectories as $directory) {
                $disk->deleteDirectory($directory);
            }
            throw $exception;
        } finally {
            $zip->close();
            $disk->deleteDirectory($staging);
        }
    }

    private function verifyArchive(ZipArchive $zip, int $unpackedLimit): array
    {
        $stat = $zip->statName('manifest.json');
        if (! $stat || $stat['size'] > 16 * 1024 * 1024 || $zip->numFiles > 25000) {
            throw new RuntimeException('Invalid catalog manifest size.');
        }
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $productKeys = implode(',', array_merge(self::PRODUCT_FIELDS, ['category', 'categories', 'section', 'models', 'asset']));
        $data = Validator::make(['manifest' => $manifest], [
            'manifest' => 'required|array:format,categories,sections,models,products,assets,files', 'manifest.format' => 'required|in:sticker-fight/catalog-v1',
            'manifest.categories' => 'present|array|max:1000', 'manifest.categories.*' => 'array:name,slug,description,active', 'manifest.categories.*.slug' => 'required|alpha_dash|max:100|distinct', 'manifest.categories.*.name' => 'required|string|max:255', 'manifest.categories.*.description' => 'nullable|string|max:255', 'manifest.categories.*.active' => 'required|boolean',
            'manifest.sections' => 'present|array|max:1000', 'manifest.sections.*' => 'array:'.implode(',', self::SECTION_FIELDS).',parent', 'manifest.sections.*.slug' => 'required|alpha_dash|max:100|distinct', 'manifest.sections.*.parent' => 'nullable|string',
            'manifest.models' => 'present|array|max:1000', 'manifest.models.*' => 'array:name,slug,manufacturer,active,section', 'manifest.models.*.slug' => 'required|alpha_dash|max:100|distinct',
            'manifest.products' => 'required|array|min:1|max:10000', 'manifest.products.*' => 'array:'.$productKeys, 'manifest.products.*.slug' => 'required|alpha_dash|max:255|distinct', 'manifest.products.*.name' => 'required|string|max:255', 'manifest.products.*.description' => 'nullable|string|max:10000', 'manifest.products.*.art_key' => 'nullable|alpha_dash|max:100', 'manifest.products.*.price_iqd' => 'nullable|integer|min:0', 'manifest.products.*.active' => 'required|boolean', 'manifest.products.*.is_featured' => 'required|boolean', 'manifest.products.*.featured_order' => 'required|integer|min:0', 'manifest.products.*.personalization_enabled' => 'required|boolean', 'manifest.products.*.personalization_layout' => 'nullable|array:x,y,width,height,font_size,color', 'manifest.products.*.categories' => 'present|array', 'manifest.products.*.models' => 'present|array', 'manifest.products.*.catalog_source' => 'nullable|alpha_dash|max:40', 'manifest.products.*.catalog_source_id' => 'nullable|string|max:64',
            'manifest.assets' => 'present|array|max:10000', 'manifest.assets.*' => 'array:id,path,thumbnail_path,width,height,sha256', 'manifest.assets.*.id' => 'required|uuid|distinct', 'manifest.assets.*.width' => 'required|integer|between:1,16000', 'manifest.assets.*.height' => 'required|integer|between:1,16000', 'manifest.assets.*.sha256' => 'required|regex:/^[a-f0-9]{64}$/D', 'manifest.files' => 'present|array|max:20000',
        ])->validate()['manifest'];
        $expected = ['manifest.json' => true];
        foreach ($data['assets'] as $asset) {
            foreach (['path' => 'original.png', 'thumbnail_path' => 'thumbnail.webp'] as $key => $filename) {
                if (($asset[$key] ?? '') !== 'catalog/'.$asset['id'].'/'.$filename) {
                    throw new RuntimeException('Invalid catalog asset path.');
                }
                $expected[$asset[$key]] = true;
            }
        }
        if (array_diff_key($expected, ['manifest.json' => true] + $data['files']) || array_diff_key($data['files'], $expected)) {
            throw new RuntimeException('The catalog file manifest does not match its assets.');
        }
        $size = 0;
        $seen = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $name = $entry['name'];
            $zip->getExternalAttributesIndex($index, $system, $attributes);
            if (! isset($expected[$name]) || isset($seen[$name]) || ($system === ZipArchive::OPSYS_UNIX && ($attributes >> 16 & 0170000) === 0120000)) {
                throw new RuntimeException('Unexpected catalog archive member.');
            }
            $seen[$name] = true;
            $size += $entry['size'];
            if ($size > $unpackedLimit || $entry['size'] > 64 * 1024 * 1024 || ($name !== 'manifest.json' && (($data['files'][$name]['bytes'] ?? null) !== $entry['size'] || ! preg_match('/^[a-f0-9]{64}$/D', $data['files'][$name]['sha256'] ?? '')))) {
                throw new RuntimeException('The catalog archive exceeds its limits or has an invalid file.');
            }
        }
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('The catalog archive is incomplete.');
        }
        $categories = array_column($data['categories'], null, 'slug');
        $sections = array_column($data['sections'], null, 'slug');
        $models = array_column($data['models'], null, 'slug');
        $assets = array_column($data['assets'], null, 'id');
        foreach ($data['products'] as $row) {
            if (($row['category'] && ! isset($categories[$row['category']])) || ($row['section'] && ! isset($sections[$row['section']])) || ($row['asset'] && ! isset($assets[$row['asset']])) || array_diff($row['categories'], array_keys($categories)) || array_diff($row['models'], array_keys($models))) {
                throw new RuntimeException('A catalog product reference is missing.');
            }
        }
        foreach ($data['sections'] as $row) {
            $parent = $row['parent'];
            $visited = [$row['slug'] => true];
            while ($parent) {
                if (! isset($sections[$parent]) || isset($visited[$parent])) {
                    throw new RuntimeException('Invalid catalog section hierarchy.');
                }
                $visited[$parent] = true;
                $parent = $sections[$parent]['parent'];
            }
        }
        foreach ($data['models'] as $row) {
            if (! isset($sections[$row['section']])) {
                throw new RuntimeException('A device model section is missing.');
            }
        }

        return $data;
    }
}
