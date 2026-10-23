<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_CATALOG = 'catalog';

    protected $table = 'assets';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [];
    }
}
