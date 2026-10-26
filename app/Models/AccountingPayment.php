<?php

namespace App\Models;

use Database\Factories\AccountingPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPayment extends Model
{
    /** @use HasFactory<AccountingPaymentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_iqd' => 'integer', 'paid_on' => 'date'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(AccountingEntry::class, 'accounting_entry_id');
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
