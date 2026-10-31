<?php

namespace App\Console\Commands;

use App\Models\GuestSession;
use App\Services\LicensedCatalogImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportLicensedCatalog extends Command
{
    protected $signature = 'sticker:catalog-import-licensed {manifest : Local JSON manifest and image directory} {--report= : Private JSON result path} {--limit= : Limit this batch; rerunning skips imported source IDs}';

    protected $description = 'Import an owner-authorized image manifest with multiple categories, preserving existing products and prices.';

    public function handle(LicensedCatalogImporter $importer): int
    {
        $path = realpath((string) $this->argument('manifest')) ?: realpath(base_path((string) $this->argument('manifest')));
        if (! $path || ! is_file($path) || filesize($path) > 100 * 1024 * 1024) {
            $this->error('Readable manifest up to 100 MB required.');

            return self::FAILURE;
        }
        try {
            $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $data = Validator::make(is_array($manifest) ? $manifest : [], ['source' => 'required|alpha_dash|max:40', 'license_confirmed' => 'accepted', 'products' => 'required|array|max:10000'])->validate();
            $limit = $this->option('limit');
            if ($limit !== null) {
                Validator::make(['limit' => $limit], ['limit' => 'integer|between:1,10000'])->validate();
            }
        } catch (\JsonException|ValidationException $exception) {
            $this->error('Invalid manifest or missing owner rights confirmation.');

            return self::FAILURE;
        }
        $rows = $limit === null ? $data['products'] : array_slice($data['products'], 0, (int) $limit);
        $previousGuest = session('guest_id');
        $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', random_bytes(32)), 'last_seen_at' => now()]);
        session(['guest_id' => $guest->id]);
        $results = [];
        $counts = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            foreach ($rows as $index => $row) {
                try {
                    $result = $importer->import($data['source'], is_array($row) ? $row : [], dirname($path));
                    $counts[$result['state']]++;
                    $results[] = ['external_id' => $row['external_id']] + $result;
                } catch (\Throwable $exception) {
                    $counts['failed']++;
                    $message = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : 'Import failed; see private application log.';
                    $results[] = ['external_id' => is_array($row) ? ($row['external_id'] ?? null) : null, 'state' => 'failed', 'error' => $message];
                    $this->error('Row '.($index + 1).': '.$message);
                    report($exception);
                }
                if (($index + 1) % 50 === 0 || $index === array_key_last($rows)) {
                    $this->line('Processed '.($index + 1).'/'.count($rows).' '.json_encode($counts));
                    $this->writeReport($counts, $results);
                }
            }
        } finally {
            Storage::disk('local')->deleteDirectory('assets/'.$guest->id);
            $guest->delete();
            session(['guest_id' => $previousGuest]);
        }
        $this->info('Created: '.$counts['created'].'; linked existing: '.$counts['linked'].'; skipped: '.$counts['skipped'].'; failed: '.$counts['failed'].'.');

        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }

    private function writeReport(array $counts, array $records): void
    {
        if ($this->option('report')) {
            $path = (string) $this->option('report');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode(['counts' => $counts, 'records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }
}
