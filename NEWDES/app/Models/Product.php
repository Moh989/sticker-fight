<?php

namespace App\Models;

use App\Services\PricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    public const BESTSELLER_THRESHOLD = 10;

    protected $table = 'products';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_demo' => 'boolean', 'featured_order' => 'integer', 'is_featured' => 'boolean', 'price_iqd' => 'integer', 'popularity_count' => 'integer', 'catalog_metadata' => 'array', 'personalization_enabled' => 'boolean', 'personalization_layout' => 'array'];
    }

    public function scopeBestsellers(Builder $query): Builder
    {
        return $query->where('active', true)->where('popularity_count', '>', self::BESTSELLER_THRESHOLD);
    }

    public function scopeCardSkins(Builder $query): Builder
    {
        return $query->whereNull('product_section_id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('active', true)->where(fn (Builder $types): Builder => $types->whereNull('product_section_id')->orWhereHas('section', fn (Builder $section): Builder => $section->where('active', true)));
    }

    public function deviceModels(): BelongsToMany
    {
        return $this->belongsToMany(DeviceModel::class);
    }

    public function scopeFeaturedFirst(Builder $query): Builder
    {
        return $query->orderByDesc('is_featured')->orderBy('featured_order');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ProductSection::class, 'product_section_id');
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

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }
}
