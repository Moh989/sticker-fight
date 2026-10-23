<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('popularity_count')->default(0)->index();
        });
        Schema::create('product_popularity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('source_key', 100)->unique();
            $table->timestamp('created_at');
        });

        DB::table('cart_items')->whereNotNull('product_id')->orderBy('id')->chunk(500, function ($items) {
            foreach ($items as $item) {
                $this->record($item->product_id, 'cart', 'cart:'.$item->design_version_id, $item->created_at);
            }
        });
        DB::table('order_items')->orderBy('id')->chunkById(500, function ($items) {
            foreach ($items as $item) {
                $productId = json_decode($item->snapshot, true)['options']['product_id'] ?? null;
                if ($productId && DB::table('products')->where('id', $productId)->exists()) {
                    $this->record((int) $productId, 'order', 'order:'.$item->order_id.':'.$productId, $item->created_at);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_popularity_events');
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['popularity_count']);
            $table->dropColumn('popularity_count');
        });
    }

    private function record(int $productId, string $kind, string $sourceKey, ?string $createdAt): void
    {
        DB::transaction(function () use ($productId, $kind, $sourceKey, $createdAt) {
            $inserted = DB::table('product_popularity_events')->insertOrIgnore([
                'product_id' => $productId, 'kind' => $kind, 'source_key' => $sourceKey, 'created_at' => $createdAt ?? now(),
            ]);
            if ($inserted) {
                DB::table('products')->where('id', $productId)->increment('popularity_count');
            }
        }, 3);
    }
};
