<?php

namespace App\Models;

use Database\Factories\CatalogRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogRequest extends Model
{
    /** @use HasFactory<CatalogRequestFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUSES = ['new' => 'جديد', 'in_review' => 'قيد المراجعة', 'quoted' => 'تم تحديد السعر', 'closed' => 'مغلق'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'quantity' => 'integer', 'quoted_iqd' => 'integer', 'width_mm' => 'decimal:2', 'height_mm' => 'decimal:2'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ProductSection::class, 'product_section_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function getNumberAttribute(): string
    {
        return 'SFQ-'.strtoupper(substr($this->id, -12));
    }
}
