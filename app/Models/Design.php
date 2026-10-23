<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Design extends Model
{
    protected $table = 'designs';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['document' => 'array', 'options' => 'array'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DesignVersion::class);
    }
}
