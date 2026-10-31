<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected static function booted(): void
    {
        static::updating(function ($item) {
            if ($item->isDirty(['snapshot', 'quantity', 'unit_price_iqd', 'design_version_id', 'order_id', 'publication_consent', 'publication_consented_at'])) {
                throw new \LogicException('Frozen order item cannot be edited.');
            }
        });
    }

    protected $table = 'order_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'publication_consent' => 'boolean', 'publication_consented_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProductionFile::class);
    }
}
