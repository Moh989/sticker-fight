<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignVersion extends Model
{
    protected $table = 'design_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['document' => 'array', 'options' => 'array', 'templates' => 'array'];
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    protected static function booted(): void
    {
        static::updating(function ($model) {
            throw new \LogicException('Immutable version: create a new version.');
        });
        static::deleting(function ($model) {
            throw new \LogicException('Versions cannot be deleted.');
        });
    }
}
