<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('catalog_source', 40)->nullable();
            $table->string('catalog_source_id', 64)->nullable();
            $table->json('catalog_metadata')->nullable();
            $table->unique(['catalog_source', 'catalog_source_id']);
        });
        Schema::create('category_product', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['catalog_source', 'catalog_source_id']);
            $table->dropColumn(['catalog_source', 'catalog_source_id', 'catalog_metadata']);
        });
    }
};
