<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $table = 'materials';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'transparent' => 'boolean'];
    }
}
