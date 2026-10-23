<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class VerifyStoreBackup extends Command
{
    protected $signature = 'sticker:backup-verify {archive}';

    protected $description = 'Verify the password and every SHA-256 digest in a backup without restoring it.';

    public function handle(): int
    {
        $path = $this->argument('archive');
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            $this->error('Could not open backup.');

            return self::FAILURE;
        }$zip->setPassword((string) config('sticker.backup_password'));
        $content = $zip->getFromName('manifest.json');
        if ($content === false) {
            $this->error('Incorrect password or missing backup manifest.');
            $zip->close();

            return self::FAILURE;
        }$manifest = json_decode($content, true);
        if (empty($manifest['sha256'])) {
            $this->error('Invalid manifest.');
            $zip->close();

            return self::FAILURE;
        }foreach ($manifest['sha256'] as $entry => $hash) {
            $bytes = $zip->getFromName($entry);
            if ($bytes === false || ! hash_equals($hash, hash('sha256', $bytes))) {
                $this->error('Checksum verification failed.');
                $zip->close();

                return self::FAILURE;
            }
        }$zip->close();
        $this->info('Backup verified: '.count($manifest['sha256']).' files. No data was restored.');

        return self::SUCCESS;
    }
}
