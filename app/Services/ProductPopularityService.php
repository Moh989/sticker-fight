<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class ProductPopularityService
{
    public function recordCartAddition(CartItem $item): void
    {
        if ($item->product_id) {
            $this->record((int) $item->product_id, 'cart', 'cart:'.$item->design_version_id);
        }
    }

    public function recordOrderRequest(OrderItem $item): void
    {
        $productId = $item->snapshot['options']['product_id'] ?? null;
        if ($productId) {
            $this->record((int) $productId, 'order', 'order:'.$item->order_id.':'.$productId);
        }
    }

    private function record(int $productId, string $kind, string $sourceKey): void
    {
        DB::transaction(function () use ($productId, $kind, $sourceKey) {
            if (! DB::table('products')->where('id', $productId)->exists()) {
                return;
            }
            $inserted = DB::table('product_popularity_events')->insertOrIgnore([
                'product_id' => $productId, 'kind' => $kind, 'source_key' => $sourceKey, 'created_at' => now(),
            ]);
            if ($inserted) {
                DB::table('products')->where('id', $productId)->increment('popularity_count');
            }
        }, 3);
    }
}
