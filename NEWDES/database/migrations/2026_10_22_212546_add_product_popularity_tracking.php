<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    }

    public function down(): void
    {
        Schema::dropIfExists('product_popularity_events');
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['popularity_count']);
            $table->dropColumn('popularity_count');
        });
    }
};
