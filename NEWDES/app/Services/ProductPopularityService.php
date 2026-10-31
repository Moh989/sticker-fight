<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\GuestSession;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductPopularityService
{
    public function recordCartAddition(CartItem $item): void
    {
        if (! config('sticker.track_cart_additions') || ! $item->product_id || (auth()->check() && auth()->user()->role !== 'customer')) {
            return;
        }
        DB::transaction(function () use ($item): void {
            $guest = GuestSession::whereKey($item->guest_session_id)->lockForUpdate()->firstOrFail();
            $product = Product::publiclyVisible()->where('is_demo', false)->find($item->product_id);
            if (! $product) {
                return;
            }
            if (! $guest->cart_token) {
                $guest->update(['cart_token' => (string) Str::uuid()]);
            }
            $inserted = DB::table('product_popularity_events')->insertOrIgnore([
                'product_id' => $product->id, 'kind' => 'cart',
                'source_key' => 'cart:'.$guest->cart_token.':'.$product->id, 'created_at' => now(),
            ]);
            if ($inserted) {
                $product->increment('popularity_count');
            }
        }, 3);
    }
}
