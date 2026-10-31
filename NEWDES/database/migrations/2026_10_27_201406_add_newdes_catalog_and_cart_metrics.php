<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('featured_order')->default(0)->index();
            $table->boolean('is_demo')->default(false);
        });
        Schema::table('guest_sessions', function (Blueprint $table): void {
            $table->uuid('cart_token')->nullable();
        });
        Schema::table('product_sections', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('product_sections')->restrictOnDelete();
            $table->string('audience', 20)->default('devices');
        });
        Schema::create('device_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_section_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 100)->unique();
            $table->string('manufacturer', 100)->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
        Schema::create('device_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_model_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->unsignedInteger('version');
            $table->json('geometry');
            $table->string('sample_reference', 200)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
            $table->unique(['device_model_id', 'version']);
        });
        Schema::create('device_model_product', function (Blueprint $table): void {
            $table->foreignId('device_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['device_model_id', 'product_id']);
        });
        Schema::create('wholesale_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_section_id')->constrained()->restrictOnDelete();
            $table->string('label', 150);
            $table->unsignedInteger('minimum_quantity');
            $table->unsignedBigInteger('unit_price_iqd');
            $table->foreignId('material_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
        Schema::table('catalog_requests', function (Blueprint $table): void {
            $table->string('kind', 20)->default('device');
            $table->foreignId('device_model_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('device_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->string('shape', 100)->nullable();
            $table->string('material', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('device_model_id');
            $table->dropConstrainedForeignId('device_template_id');
            $table->dropConstrainedForeignId('asset_id');
            $table->dropColumn(['kind', 'shape', 'material']);
        });
        Schema::dropIfExists('wholesale_tiers');
        Schema::dropIfExists('device_model_product');
        Schema::dropIfExists('device_templates');
        Schema::dropIfExists('device_models');
        Schema::table('product_sections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('audience');
        });
        Schema::table('guest_sessions', fn (Blueprint $table) => $table->dropColumn('cart_token'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['featured_order', 'is_demo']));
    }
};
