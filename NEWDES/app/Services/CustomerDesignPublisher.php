<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\CatalogAsset;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerDesignPublisher
{
    public const CATEGORY_SLUG = 'your-cute-designs';

    public const SOURCE = 'customer-design';

    public function __construct(private ExportService $export, private CatalogService $catalog) {}

    public function publish(OrderItem $item): void
    {
        $item->refresh();
        $snapshot = $item->snapshot;
        if (! $item->publication_consent || ! $item->publication_consented_at || ! empty($snapshot['options']['product_id']) || ! empty($snapshot['options']['ready_locked'])) {
            return;
        }
        if (! isset($snapshot['document']) || $item->export_status === 'expired') {
            throw new \RuntimeException('The shared design files have expired.');
        }
        $options = $snapshot['options'];
        $sides = $options['mode'] === 'single' ? [$options['printed_side']] : ['front', 'back'];
        $sides = array_values(array_filter($sides, fn (string $side): bool => $options['mode'] !== 'mixed' || $side !== $options['transparent_side']));
        $sourceIds = array_map(fn (string $side): string => $item->id.':'.$side, $sides);
        if (Product::withTrashed()->where('catalog_source', self::SOURCE)->whereIn('catalog_source_id', $sourceIds)->count() === count($sides)) {
            $item->update(['publication_status' => 'published']);

            return;
        }

        $item->update(['publication_status' => 'processing']);
        $directory = 'catalog-publications/'.$item->id.'/'.Str::uuid();
        $createdAssets = [];
        try {
            $files = collect($this->export->catalogArtwork($item, $directory))->where('kind', 'catalog')->keyBy('side');
            DB::transaction(function () use ($item, $sides, $files, $directory, &$createdAssets): void {
                OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
                $category = Category::firstOrCreate(['slug' => self::CATEGORY_SLUG], ['name' => 'تصاميمكم اللطيفة', 'active' => true]);
                foreach ($sides as $side) {
                    $sourceId = $item->id.':'.$side;
                    if (Product::withTrashed()->where('catalog_source', self::SOURCE)->where('catalog_source_id', $sourceId)->exists()) {
                        continue;
                    }
                    $file = $files->get($side);
                    if (! $file || $file['name'] !== $side.'-catalog.png') {
                        throw new \RuntimeException('The shared artwork output is incomplete.');
                    }
                    $path = $directory.'/'.$file['name'];
                    $disk = Storage::disk('local');
                    $info = getimagesize($disk->path($path));
                    if (! $info || $info['mime'] !== 'image/png' || config('sticker.max_pixels') < $info[0] * $info[1]) {
                        throw new \RuntimeException('The shared artwork is invalid.');
                    }
                    $hash = hash_file('sha256', $disk->path($path));
                    $art = CatalogAsset::where('sha256', $hash)->first();
                    if (! $art) {
                        $art = $this->catalog->publish(new Asset(['path' => $path, 'width' => $info[0], 'height' => $info[1], 'sha256' => $hash]));
                        $createdAssets[] = $art->id;
                    }
                    $product = Product::create(['name' => 'تصميم لطيف '.Str::upper(Str::random(6)), 'slug' => 'your-design-'.Str::uuid(), 'category_id' => $category->id, 'catalog_asset_id' => $art->id, 'price_iqd' => null, 'active' => true, 'is_featured' => false, 'popularity_count' => 0, 'catalog_source' => self::SOURCE, 'catalog_source_id' => $sourceId, 'catalog_metadata' => ['side' => $side, 'shared_with_consent' => true]]);
                    $product->categories()->syncWithoutDetaching([$category->id]);
                    AuditLog::create(['action' => 'customer_design.published', 'subject_type' => 'products', 'subject_id' => (string) $product->id, 'changes' => ['order_item_id' => $item->id, 'side' => $side, 'consented_at' => $item->publication_consented_at->toIso8601String()]]);
                }
                $item->update(['publication_status' => 'published']);
            });
        } catch (\Throwable $exception) {
            $item->update(['publication_status' => 'failed']);
            foreach ($createdAssets as $assetId) {
                if (! CatalogAsset::whereKey($assetId)->exists()) {
                    Storage::disk('local')->deleteDirectory('catalog/'.$assetId);
                }
            }
            throw $exception;
        } finally {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }
}
