<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'key';

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
