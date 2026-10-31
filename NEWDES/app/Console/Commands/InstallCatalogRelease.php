<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class InstallCatalogRelease extends Command
{
    protected $signature = 'sticker:catalog-install {--archive= : Use an already downloaded release ZIP}';

    protected $description = 'Verify and import the catalog package attached to the configured GitHub release.';

    public function handle(): int
    {
        $release = config('catalog.release');
        if (! is_array($release) || ! preg_match('/^[a-f0-9]{64}$/D', $release['sha256'] ?? '') || ($release['products'] ?? 0) < 1) {
            $this->error('The catalog release is not configured.');

            return self::FAILURE;
        }
        $base = Storage::disk('local')->path('catalog-imports/'.substr($release['sha256'], 0, 16));
        File::ensureDirectoryExists($base);
        $archive = $this->option('archive') ? realpath((string) $this->option('archive')) : $base.'/package.zip';
        try {
            if (! $this->option('archive') && (! is_file($archive) || ! hash_equals($release['sha256'], hash_file('sha256', $archive)))) {
                if (! str_starts_with($release['url'] ?? '', 'https://')) {
                    throw new RuntimeException('The release URL must use HTTPS.');
                }
                $this->info('Downloading the catalog package. Large downloads may take several minutes.');
                $partial = $base.'/package.zip.part';
                try {
                    Http::connectTimeout(30)->timeout(1800)->sink($partial)->get($release['url'])->throw();
                    if (! hash_equals($release['sha256'], hash_file('sha256', $partial))) {
                        throw new RuntimeException('Catalog SHA-256 verification failed.');
                    }
                    File::move($partial, $archive);
                } finally {
                    File::delete($partial);
                }
            }
            if (! $archive || ! is_file($archive) || ! hash_equals($release['sha256'], hash_file('sha256', $archive))) {
                throw new RuntimeException('Catalog SHA-256 verification failed.');
            }
            $source = $base.'/source';
            $this->extractVerified($archive, $source, $release);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Catalog download or verification failed. No products were imported. Check the private log and retry.');

            return self::FAILURE;
        }
        $this->info('Package verified. Importing catalog images and categories; existing designs will be preserved.');

        return $this->call('sticker:catalog-import-licensed', ['manifest' => $source.'/manifest.json', '--report' => $base.'/import-report.json']);
    }

    private function extractVerified(string $archive, string $destination, array $release): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Invalid catalog ZIP.');
        }
        try {
            if ($zip->numFiles !== $release['products'] + 1) {
                throw new RuntimeException('Unexpected archive member count.');
            }
            $size = 0;
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'];
                if ($name !== 'manifest.json' && ! preg_match('~^originals/[0-9]+\.png$~D', $name)) {
                    throw new RuntimeException('Unexpected catalog archive path.');
                }
                if (isset($names[$name])) {
                    throw new RuntimeException('Duplicate archive path.');
                }
                $names[$name] = true;
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if ($system === ZipArchive::OPSYS_UNIX && ($attributes >> 16 & 0170000) === 0120000) {
                    throw new RuntimeException('Catalog symlinks are not allowed.');
                }
                $size += $stat['size'];
            }
            if ($size > $release['unpacked_limit']) {
                throw new RuntimeException('The catalog package exceeds its unpacked limit.');
            }
            $manifestBytes = $zip->getFromName('manifest.json');
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            if (count($manifest['products'] ?? []) !== $release['products']) {
                throw new RuntimeException('The manifest product count does not match the release.');
            }
            foreach ($manifest['products'] as $row) {
                if (! isset($names[$row['file'] ?? '']) || ! preg_match('/^[a-f0-9]{64}$/D', $row['metadata']['sha256'] ?? '')) {
                    throw new RuntimeException('A manifest image is missing from the archive.');
                }
            }
            $cached = File::exists($destination.'/.package-sha256') && trim(File::get($destination.'/.package-sha256')) === $release['sha256'];
            if ($cached && (! is_file($destination.'/manifest.json') || ! hash_equals(hash('sha256', $manifestBytes), hash_file('sha256', $destination.'/manifest.json')))) {
                $cached = false;
            }
            foreach (array_keys($names) as $name) {
                if (! is_file($destination.'/'.$name) || is_link($destination.'/'.$name)) {
                    $cached = false;
                    break;
                }
            }
            if ($cached) {
                foreach ($manifest['products'] as $row) {
                    if (! hash_equals($row['metadata']['sha256'], hash_file('sha256', $destination.'/'.$row['file']))) {
                        $cached = false;
                        break;
                    }
                }
            }
            if (! $cached) {
                File::deleteDirectory($destination);
                File::ensureDirectoryExists($destination);
                if (! $zip->extractTo($destination)) {
                    throw new RuntimeException('Could not extract the catalog.');
                }
                File::put($destination.'/.package-sha256', $release['sha256']);
            }
        } finally {
            $zip->close();
        }
    }
}
