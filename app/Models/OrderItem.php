<?php

namespace App\Models;

use App\Services\ProductPopularityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected static function booted(): void
    {
        static::created(fn (OrderItem $item) => app(ProductPopularityService::class)->recordOrderRequest($item));
        static::updating(function ($item) {
            if ($item->isDirty(['snapshot', 'quantity', 'unit_price_iqd', 'design_version_id', 'order_id'])) {
                throw new \LogicException('Frozen order item cannot be edited.');
            }
        });
    }

    protected $table = 'order_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
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
