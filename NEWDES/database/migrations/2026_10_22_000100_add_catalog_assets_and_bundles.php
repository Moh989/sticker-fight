<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('path');
            $t->string('thumbnail_path');
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->string('sha256', 64);
            $t->timestamps();
        });
        Schema::table('products', function (Blueprint $t) {
            $t->foreignUuid('catalog_asset_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('bundles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('minimum_quantity');
            $t->unsignedInteger('discount_percent');
            $t->boolean('active')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundles');
        Schema::table('products', fn (Blueprint $t) => $t->dropConstrainedForeignId('catalog_asset_id'));
        Schema::dropIfExists('catalog_assets');
    }
};
