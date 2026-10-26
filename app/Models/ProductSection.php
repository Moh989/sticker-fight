<?php

namespace App\Models;

use Database\Factories\ProductSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
