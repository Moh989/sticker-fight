<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Finish extends Model
{
    protected $table = 'finishes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
