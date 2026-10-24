<?php

namespace App\Models;

use App\Services\PricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    public const BESTSELLER_THRESHOLD = 10;

    protected $table = 'products';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_featured' => 'boolean', 'price_iqd' => 'integer', 'popularity_count' => 'integer'];
    }

    public function scopeBestsellers(Builder $query): Builder
    {
        return $query->where('active', true)->where('popularity_count', '>', self::BESTSELLER_THRESHOLD);
    }

    public function getIsBestsellerAttribute(): bool
    {
        return $this->active && $this->popularity_count > self::BESTSELLER_THRESHOLD;
    }

    public function catalogAsset(): BelongsTo
    {
        return $this->belongsTo(CatalogAsset::class);
    }

    public function getImageUrlAttribute(): string
    {
        return $this->catalog_asset_id ? url('/catalog/'.$this->catalog_asset_id) : url('/art/'.$this->art_key.'.svg');
    }

    public function getBasePriceIqdAttribute(): ?int
    {
        return app(PricingService::class)->basePrice('single', $this);
    }

    public function getPreviewImageUrlAttribute(): string
    {
        return $this->catalog_asset_id ? url('/catalog/'.$this->catalog_asset_id).'?preview=1' : $this->image_url;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
