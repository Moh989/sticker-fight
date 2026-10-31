<?php

namespace App\Models;

use Database\Factories\AccountingEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingEntry extends Model
{
    /** @use HasFactory<AccountingEntryFactory> */
    use HasFactory;

    use HasUuids;

    public const TYPES = ['material_purchase' => 'شراء مواد أولية', 'equipment_purchase' => 'شراء جهاز أو أصل', 'capital_in' => 'إضافة رأس مال', 'capital_out' => 'سحب من رأس المال', 'expense' => 'مصروف تشغيل'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_iqd' => 'integer', 'paid_iqd' => 'integer', 'quantity_milli' => 'integer', 'occurred_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountingPayment::class);
    }

    public function getNumberAttribute(): string
    {
        return 'AC-'.strtoupper(substr($this->id, -12));
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['paid_iqd', 'voided_at', 'void_reason', 'updated_at'])) {
                throw new \LogicException('Posted accounting records are immutable.');
            }
        });
        static::deleting(function (): void {
            throw new \LogicException('Reverse accounting records instead of deleting them.');
        });
    }
}
