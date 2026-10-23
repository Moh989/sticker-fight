<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupStore extends Command
{
    protected $signature = 'sticker:backup';

    protected $description = 'Create an AES-256 encrypted backup of MySQL and private assets.';

    public function handle(): int
    {
        $password = config('sticker.backup_password');
        if (! is_string($password) || strlen($password) < 16) {
            $this->error('Set BACKUP_PASSWORD to at least 16 characters in the environment.');

            return self::FAILURE;
        }
        $disk = Storage::disk('local');
        $name = 'backups/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4));
        $disk->makeDirectory($name);
        $dump = $disk->path($name.'/database.sql');
        $db = config('database.connections.mysql');
        if (config('database.default') !== 'mysql') {
            $this->error('This command requires MySQL.');

            return self::FAILURE;
        }
        $process = new Process([config('sticker.mysqldump'), '--single-transaction', '--set-gtid-purged=OFF', '--no-tablespaces', '--skip-lock-tables', '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'], '--result-file='.$dump, $db['database']], base_path(), ['MYSQL_PWD' => $db['password']], null, 300);
        try {
            $process->mustRun();
            chmod($dump, 0600);
            $zipPath = $disk->path($name.'.zip');
            $zip = new \ZipArchive;
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create encrypted ZIP.');
            }$zip->setPassword($password);
            $manifest = [];
            $add = function (string $file, string $entry) use ($zip, &$manifest) {
                $zip->addFile($file, $entry);
                $zip->setEncryptionName($entry, \ZipArchive::EM_AES_256);
                $manifest[$entry] = hash_file('sha256', $file);
            };
            $add($dump, 'database.sql');
            foreach ($disk->allFiles() as $file) {
                if (str_starts_with($file, 'backups/')) {
                    continue;
                }$add($disk->path($file), 'private/'.$file);
            }$add(app()->environmentFilePath(), 'environment.txt');
            $zip->addFromString('manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => $db['database'], 'sha256' => $manifest], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $zip->setEncryptionName('manifest.json', \ZipArchive::EM_AES_256);
            $zip->close();
            chmod($zipPath, 0600);
            $disk->deleteDirectory($name);
            foreach ($disk->files('backups') as $old) {
                if (str_ends_with($old, '.zip') && $disk->lastModified($old) < now()->subDays(config('sticker.backup_retention_days'))->timestamp) {
                    $disk->delete($old);
                }
            }$this->info('Encrypted backup created: backups/'.basename($zipPath));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $disk->deleteDirectory($name);
            report($e);
            $this->error('Backup failed. Review the private server log.');

            return self::FAILURE;
        }
    }
}
