<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportRun extends Model
{
    protected $table = 'export_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }
}
