<?php

namespace App\Console\Commands;

use App\Services\CatalogSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class InstallCatalogSnapshot extends Command
{
    protected $signature = 'sticker:catalog-restore {--archive= : Use an already downloaded snapshot ZIP}';

    protected $description = 'Install the verified NEWDES catalog release, preserving existing owner edits and deleted products.';

    public function handle(CatalogSnapshot $catalog): int
    {
        $release = config('catalog.snapshot');
        if (! is_array($release) || ! preg_match('/^[a-f0-9]{64}$/D', $release['sha256'] ?? '')) {
            $this->error('The NEWDES catalog release is not configured.');

            return self::FAILURE;
        }
        try {
            return Cache::lock('catalog-snapshot-install', 3600)->block(1, function () use ($catalog, $release): int {
                $archive = $this->option('archive') ? realpath((string) $this->option('archive')) : Storage::disk('local')->path('catalog-imports/newdes-'.$release['sha256'].'.zip');
                if (! $this->option('archive') && (! is_file($archive) || ! hash_equals($release['sha256'], hash_file('sha256', $archive)))) {
                    if (! str_starts_with($release['url'] ?? '', 'https://')) {
                        throw new \RuntimeException('The catalog release must use HTTPS.');
                    }
                    File::ensureDirectoryExists(dirname($archive));
                    $this->info('Downloading the NEWDES catalog; this may take several minutes.');
                    $partial = $archive.'.part';
                    try {
                        Http::connectTimeout(30)->timeout(3600)->sink($partial)->get($release['url'])->throw();
                        if (! hash_equals($release['sha256'], hash_file('sha256', $partial))) {
                            throw new \RuntimeException('Catalog SHA-256 verification failed.');
                        }
                        File::move($partial, $archive);
                    } finally {
                        File::delete($partial);
                    }
                }
                $result = $catalog->install($archive ?: '', $release['sha256'], (int) $release['unpacked_limit']);
                $this->info('Created: '.$result['created'].'; preserved existing: '.$result['skipped'].'.');

                return self::SUCCESS;
            });
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Catalog restore failed. No catalog changes were committed. Check the private log and retry.');

            return self::FAILURE;
        }
    }
}
