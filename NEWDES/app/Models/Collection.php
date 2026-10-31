<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    public static function netFor(string $orderId): int
    {
        return (int) static::where('order_id', $orderId)->where('method', 'cod_manual')->sum('amount_iqd') - (int) static::where('order_id', $orderId)->where('method', 'refund')->sum('amount_iqd');
    }

    protected $table = 'collections';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
