<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionFile extends Model
{
    protected $table = 'production_files';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
