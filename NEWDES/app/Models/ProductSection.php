<?php

namespace App\Models;

use Database\Factories\ProductSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductSection extends Model
{
    /** @use HasFactory<ProductSectionFactory> */
    use HasFactory;

    public const ILLUSTRATIONS = ['controller' => 'أجهزة التحكم', 'console' => 'أجهزة الكونسل', 'advertising' => 'ستيكرات إعلانية', 'hologram' => 'ستيكرات هولوغرام', 'laptop' => 'لابتوبات وتابلت', 'packaging' => 'ملصقات التغليف', 'qr' => 'ستيكرات QR'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'requires_model' => 'boolean', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('active', true)->orderBy('sort_order');
    }

    public function deviceModels(): HasMany
    {
        return $this->hasMany(DeviceModel::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
