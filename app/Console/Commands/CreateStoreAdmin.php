<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateStoreAdmin extends Command
{
    protected $signature = 'sticker:admin {--email=} {--name=} {--password-stdin} {--local-access}';

    protected $description = 'Create an administrator with a hidden password; no default credentials.';

    public function handle(): int
    {
        $local = (bool) $this->option('local-access');
        if ($local && ! app()->environment('local')) {
            $this->error('Local access is available only in APP_ENV=local.');

            return self::FAILURE;
        }
        $email = $this->option('email') ?? ($local ? 'owner@stickerfight.test' : $this->ask('Administrator email'));
        $name = $this->option('name') ?? ($local ? 'STICKER FIGHT Owner' : $this->ask('Administrator name'));
        $password = $local ? bin2hex(random_bytes(20)) : ($this->option('password-stdin') ? rtrim(stream_get_contents(STDIN), "\r\n") : $this->secret('Password (at least 12 characters)'));
        $validator = Validator::make(compact('email', 'name', 'password'), ['email' => 'required|email|max:200|unique:users', 'name' => 'required|string|max:100', 'password' => 'required|string|min:12']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->role = 'admin';
        $user->save();
        AuditLog::create(['action' => 'admin.created', 'subject_type' => 'User', 'subject_id' => $user->id, 'changes' => ['role' => 'admin']]);
        if ($local) {
            $path = base_path('.runtime/admin-access.txt');
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }file_put_contents($path, "LOCAL DEVELOPMENT ACCESS ONLY\nURL: ".url('/login')."\nEmail: ".$email."\nPassword: ".$password."\n\nCreate your production administrator with php artisan sticker:admin.\nNever deploy this file or the local database.\n");
            chmod($path, 0600);
            $this->info('Local administrator created. Credentials are in .runtime/admin-access.txt (0600).');
        } else {
            $this->info('Administrator created.');
        }

        return self::SUCCESS;
    }
}
