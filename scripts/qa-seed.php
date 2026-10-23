<?php

use App\Models\CardTemplate;
use App\Models\Finish;
use App\Models\Material;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.qa');
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'sticker_fight_qa') {
    throw new RuntimeException('Refusing to seed non-QA database.');
}
foreach (['single' => 7000, 'double' => 12000, 'mixed' => 10000] as $mode => $price) {
    StoreSettings::put('price_'.$mode.'_iqd', $price);
}
StoreSettings::put('extra_double_iqd', 4000);
StoreSettings::put('extra_mixed_iqd', 3000);
StoreSettings::put('accepting_orders', true);
Material::query()->update(['active' => true]);
Finish::query()->update(['active' => true]);
Product::query()->update(['price_iqd' => 8000]);
CardTemplate::query()->update(['active' => true]);
TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'AUTOMATED QA ONLY — NOT A PHYSICAL APPROVAL']);
ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000, 'estimated_time' => 'QA test only']);
$password = bin2hex(random_bytes(16));
$users = [];
foreach (['admin', 'orders', 'production'] as $role) {
    $u = User::firstOrNew(['email' => $role.'@qa.local']);
    $u->name = 'QA '.$role;
    $u->role = $role;
    $u->password = $password;
    $u->save();
    $users[$role] = ['email' => $u->email, 'password' => $password];
}
file_put_contents(__DIR__.'/../.runtime/qa-users.json', json_encode($users));
chmod(__DIR__.'/../.runtime/qa-users.json', 0600);
echo "QA fixtures enabled in isolated database.\n";
