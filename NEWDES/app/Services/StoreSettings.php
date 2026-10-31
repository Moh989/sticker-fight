<?php

namespace App\Services;

use App\Models\Setting;

class StoreSettings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return Setting::find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
