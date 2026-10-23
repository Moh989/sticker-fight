<?php

namespace App\Models;

use App\Services\ProductPopularityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected static function booted(): void
    {
        static::created(fn (CartItem $item) => app(ProductPopularityService::class)->recordCartAddition($item));
    }

    protected $table = 'cart_items';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'design_version_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
