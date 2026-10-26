<?php

namespace App\Models;

use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    public const TYPES = ['purchase' => 'شراء', 'production' => 'استهلاك إنتاج', 'waste' => 'تالف / استهلاك يدوي', 'reversal' => 'عكس حركة'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer', 'value_milli_iqd' => 'integer', 'basis_before_quantity_milli' => 'integer', 'basis_before_value_milli_iqd' => 'integer', 'balance_milli' => 'integer', 'balance_value_milli_iqd' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of');
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), [])) {
                throw new \LogicException('Posted accounting records are immutable.');
            }
        });
        static::deleting(function (): void {
            throw new \LogicException('Reverse accounting records instead of deleting them.');
        });
    }
}
