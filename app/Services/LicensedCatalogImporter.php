<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\CatalogAsset;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LicensedCatalogImporter
{
    private ?array $legacyFiles = null;

    public function __construct(private AssetService $uploads, private CatalogService $catalog) {}

    /** @return array{state: string, product_id: int, active: bool, deleted: bool} */
    public function import(string $source, array $input, string $root): array
    {
        $row = Validator::make($input, [
            'external_id' => 'required|string|max:64', 'handle' => 'required|alpha_dash|max:180', 'name' => 'required|string|max:200',
            'file' => 'required|string|max:500', 'active' => 'required|boolean', 'metadata' => 'nullable|array',
            'categories' => 'required|array|min:1|max:100', 'categories.*.slug' => 'required|alpha_dash|max:100', 'categories.*.name' => 'required|string|max:100',
        ])->validate();
        $existing = Product::withTrashed()->where('catalog_source', $source)->where('catalog_source_id', $row['external_id'])->first();
        if ($existing) {
            return $this->result('skipped', $existing);
        }
        $path = realpath($root.DIRECTORY_SEPARATOR.$row['file']);
        if (! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path) || is_link($root.DIRECTORY_SEPARATOR.$row['file'])) {
            throw ValidationException::withMessages(['file' => 'صورة المصدر مفقودة أو خارج مجلد الاستيراد.']);
        }
        $asset = $newCatalog = null;
        try {
            $legacy = $this->legacyProduct($row['handle']);
            $metadata = $row['metadata'] ?? [];
            if (! $legacy) {
                $asset = $this->uploads->upload(new UploadedFile($path, basename($path), test: true));
                $rawHash = $asset->sha256;
                $metadata['normalization'] = $this->trimTransparentPadding($asset);
                $legacy = Product::withTrashed()->whereNull('catalog_source_id')->cardSkins()->whereHas('catalogAsset', fn ($query) => $query->whereIn('sha256', [$rawHash, $asset->sha256]))->first();
                if ($asset->width / $asset->height < 1.52 || $asset->width / $asset->height > 1.66) {
                    $row['active'] = false;
                    $metadata['review_reason'] = 'نسبة الصورة لا تطابق صورة بطاقة كاملة؛ تحتاج مراجعة المصدر قبل التفعيل.';
                }
                $metadata['effective_ppi'] = (int) floor(min($asset->width / 85.60, $asset->height / 53.98) * 25.4);
            }

            return DB::transaction(function () use ($source, $row, $metadata, $legacy, $asset, &$newCatalog): array {
                $categories = collect($row['categories'])->unique('slug')->map(fn (array $category): Category => Category::firstOrCreate(['slug' => $category['slug']], ['name' => $category['name'], 'active' => true]));
                if ($legacy) {
                    $product = Product::withTrashed()->whereKey($legacy->id)->lockForUpdate()->firstOrFail();
                    if ($product->catalog_source_id !== null) {
                        throw ValidationException::withMessages(['source' => 'التصميم القديم مرتبط بمصدر آخر.']);
                    }
                    $state = 'linked';
                } else {
                    $art = CatalogAsset::where('sha256', $asset->sha256)->first();
                    if (! $art) {
                        $art = $newCatalog = $this->catalog->publish($asset);
                    }
                    $slug = Str::limit($row['handle'], 100, '');
                    if (Product::withTrashed()->where('slug', $slug)->exists()) {
                        $slug = Str::limit($slug, 76, '').'-'.Str::substr(hash('sha256', $source.':'.$row['external_id']), 0, 12);
                    }
                    $product = new Product(['name' => Str::limit($row['name'], 100, ''), 'slug' => $slug, 'category_id' => $categories->first()->id, 'catalog_asset_id' => $art->id, 'price_iqd' => null, 'active' => $row['active'], 'is_featured' => false, 'popularity_count' => 0]);
                    $state = 'created';
                }
                $product->fill(['catalog_source' => $source, 'catalog_source_id' => $row['external_id'], 'catalog_metadata' => $metadata + ['handle' => $row['handle'], 'original_name' => $row['name']]])->save();
                $product->categories()->syncWithoutDetaching($categories->pluck('id')->all());
                AuditLog::create(['action' => 'catalog.licensed_import', 'subject_type' => 'products', 'subject_id' => (string) $product->id, 'changes' => ['source' => $source, 'external_id' => $row['external_id'], 'state' => $state]]);

                return $this->result($state, $product);
            });
        } finally {
            if ($asset) {
                Storage::disk('local')->delete($asset->path);
                $asset->delete();
            }
            if ($newCatalog && ! CatalogAsset::whereKey($newCatalog->id)->exists()) {
                Storage::disk('local')->deleteDirectory('catalog/'.$newCatalog->id);
            }
        }
    }

    private function result(string $state, Product $product): array
    {
        return ['state' => $state, 'product_id' => $product->id, 'active' => $product->active, 'deleted' => $product->trashed()];
    }

    private function canonicalHandle(string $handle): string
    {
        $name = pathinfo($handle, PATHINFO_FILENAME);
        if (pathinfo($handle, PATHINFO_EXTENSION) !== '') {
            $name = preg_replace('/^\d+[_-]/', '', $name);
        }

        return preg_replace(['/^cc-/', '/-card-cover$/'], '', $name);
    }

    private function legacyProduct(string $handle): ?Product
    {
        if ($this->legacyFiles === null) {
            $this->legacyFiles = [];
            foreach (AuditLog::where('action', 'catalog.imported')->whereIn('subject_type', ['products', 'Product'])->get() as $event) {
                if (! ctype_digit((string) $event->subject_id)) {
                    continue;
                }
                $file = $event->changes['file'] ?? null;
                if ($file) {
                    $this->legacyFiles[$this->canonicalHandle($file)][] = (int) $event->subject_id;
                }
            }
        }
        $ids = array_unique($this->legacyFiles[$this->canonicalHandle($handle)] ?? []);

        return count($ids) === 1 ? Product::withTrashed()->cardSkins()->whereNull('catalog_source_id')->find($ids[0]) : null;
    }

    private function trimTransparentPadding(Asset $asset): array
    {
        $path = Storage::disk('local')->path($asset->path);
        $image = imagecreatefrompng($path);
        $corner = imagecolorat($image, 0, 0);
        if (($corner >> 24 & 0x7F) === 127) {
            imagecolortransparent($image, $corner);
        }
        $crop = imagecropauto($image, IMG_CROP_TRANSPARENT);
        $from = [$asset->width, $asset->height];
        if ($crop && (imagesx($crop) !== $asset->width || imagesy($crop) !== $asset->height)) {
            imagealphablending($crop, false);
            imagesavealpha($crop, true);
            ob_start();
            imagepng($crop, null, 6);
            $bytes = ob_get_clean();
            Storage::disk('local')->put($asset->path, $bytes);
            $asset->update(['width' => imagesx($crop), 'height' => imagesy($crop), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        }
        unset($image, $crop);

        return ['transparent_padding_trimmed' => $from !== [$asset->width, $asset->height], 'from' => $from, 'to' => [$asset->width, $asset->height]];
    }
}
