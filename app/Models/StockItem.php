<?php

namespace App\Models;

use Database\Factories\StockItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockItem extends Model
{
    /** @use HasFactory<StockItemFactory> */
    use HasFactory;

    public const UNITS = ['sheet' => 'ورقة', 'ml' => 'مل', 'meter' => 'متر', 'piece' => 'قطعة', 'gram' => 'غرام'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer', 'value_milli_iqd' => 'integer', 'valuation_quantity_milli' => 'integer', 'valuation_value_milli_iqd' => 'integer', 'minimum_milli' => 'integer', 'active' => 'boolean', 'width_mm' => 'decimal:2', 'height_mm' => 'decimal:2'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
