<?php

namespace App\Models;

use Database\Factories\CostRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostRun extends Model
{
    /** @use HasFactory<CostRunFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'quantity' => 'integer', 'total_milli_iqd' => 'integer', 'voided_at' => 'datetime'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getNumberAttribute(): string
    {
        return 'PR-'.strtoupper(substr($this->id, -12));
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['voided_at', 'void_reason', 'updated_at'])) {
                throw new \LogicException('Posted accounting records are immutable.');
            }
        });
        static::deleting(function (): void {
            throw new \LogicException('Reverse accounting records instead of deleting them.');
        });
    }
}
