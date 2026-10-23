<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\CatalogAsset;
use App\Models\Category;
use App\Models\GuestSession;
use App\Models\Product;
use App\Services\AssetService;
use App\Services\CatalogService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportCatalog extends Command
{
    protected $signature = 'sticker:catalog-import {directory=sss : Folder containing category subfolders}';

    protected $description = 'Import supplied catalog images privately, preserving existing products and prices.';

    private const GROUPS = [
        'Anime' => ['أنمي', 'anime'], 'Artistic' => ['فن وتصاميم', 'artistic'],
        'Classic_Art' => ['فن كلاسيكي', 'classic-art'], 'Funny' => ['مضحك', 'funny'],
        'Memes' => ['ميمز', 'memes'], 'Retro_Nostalgic' => ['ريترو وحنين', 'retro'],
        'Animals' => ['حيوانات', 'animals'], 'Crypto' => ['عملات رقمية', 'crypto'],
    ];

    public function handle(AssetService $uploads, CatalogService $catalog): int
    {
        $input = (string) $this->argument('directory');
        $root = realpath($input) ?: realpath(base_path($input));
        if (! $root || ! is_dir($root)) {
            $this->error('Catalog directory not found.');

            return self::FAILURE;
        }
        $previousGuest = session('guest_id');
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', random_bytes(32)), 'last_seen_at' => now()]);
        session(['guest_id' => $guest->id]);
        $created = $skipped = $failed = 0;
        try {
            foreach (File::directories($root) as $folder) {
                $key = preg_replace('/^(?:CUCU_)?\d+_/', '', basename($folder));
                [$categoryName, $categorySlug] = self::GROUPS[$key] ?? [str_replace('_', ' ', $key), Str::slug($key)];
                if ($categorySlug === '' || is_link($folder)) {
                    continue;
                }
                $files = File::files($folder);
                usort($files, fn ($a, $b) => strcmp($a->getFilename(), $b->getFilename()));
                foreach ($files as $file) {
                    if ($file->isLink() || ! in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'webp'], true)) {
                        continue;
                    }
                    $asset = $newCatalog = null;
                    try {
                        $asset = $uploads->upload(new UploadedFile($file->getPathname(), $file->getFilename(), test: true));
                        if (Product::whereHas('catalogAsset', fn ($q) => $q->where('sha256', $asset->sha256))->exists()) {
                            $skipped++;

                            continue;
                        }
                        DB::transaction(function () use ($asset, $catalog, $file, $categoryName, $categorySlug, &$newCatalog) {
                            $art = CatalogAsset::where('sha256', $asset->sha256)->first();
                            if (! $art) {
                                $art = $newCatalog = $catalog->publish($asset);
                            }
                            $category = Category::firstOrCreate(['slug' => $categorySlug], ['name' => $categoryName, 'active' => true]);
                            $key = preg_replace(['/^\d+[_-]/', '/^cc-/', '/-card-cover$/'], '', $file->getFilenameWithoutExtension());
                            $slug = Str::limit($categorySlug.'-'.(Str::slug($key) ?: 'design'), 100, '');
                            if (Product::where('slug', $slug)->exists()) {
                                $slug = Str::limit($slug, 91, '').'-'.substr($asset->sha256, 0, 8);
                            }
                            $name = Str::title(str_replace(['-', '_'], ' ', $key));
                            $product = Product::create(['category_id' => $category->id, 'catalog_asset_id' => $art->id, 'name' => Str::limit($name ?: 'Design', 100, ''), 'slug' => $slug, 'price_iqd' => null, 'active' => true]);
                            AuditLog::create(['action' => 'catalog.imported', 'subject_type' => 'products', 'subject_id' => (string) $product->id, 'changes' => ['file' => $file->getFilename(), 'sha256' => $asset->sha256]]);
                        });
                        $created++;
                    } catch (ValidationException $e) {
                        $failed++;
                        $this->error($file->getFilename().': '.collect($e->errors())->flatten()->first());
                    } catch (\Throwable $e) {
                        $failed++;
                        report($e);
                        $this->error($file->getFilename().': import failed; check the private server log.');
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
            }
        } finally {
            Storage::disk('local')->deleteDirectory('assets/'.$guest->id);
            $guest->delete();
            session(['guest_id' => $previousGuest]);
        }
        $this->info("Created: {$created}; skipped existing: {$skipped}; failed: {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
