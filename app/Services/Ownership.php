<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class Ownership
{
    public static function check(Model $model): void
    {
        abort_unless($model->guest_session_id === session('guest_id') || (auth()->check() && $model->user_id === auth()->id()), 404);
    }
}
