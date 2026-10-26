<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('illustration', 30)->default('controller');
            $table->string('model_hint', 150)->nullable();
            $table->boolean('requires_model')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_section_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('catalog_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->uuid('guest_session_id')->nullable();
            $table->foreign('guest_session_id')->references('id')->on('guest_sessions')->nullOnDelete();
            $table->foreignId('product_section_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('phone', 20);
            $table->string('model', 150)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('width_mm', 8, 2)->nullable();
            $table->decimal('height_mm', 8, 2)->nullable();
            $table->text('details');
            $table->json('snapshot');
            $table->string('submission_hash', 64);
            $table->string('status', 20)->default('new')->index();
            $table->unsignedBigInteger('quoted_iqd')->nullable();
            $table->text('staff_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_requests');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_section_id');
        });
        Schema::dropIfExists('product_sections');
    }
};
