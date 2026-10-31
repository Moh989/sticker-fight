<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 60)->unique();
            $table->string('unit', 20);
            $table->decimal('width_mm', 8, 2)->nullable();
            $table->decimal('height_mm', 8, 2)->nullable();
            $table->bigInteger('quantity_milli')->default(0);
            $table->bigInteger('value_milli_iqd')->default(0);
            $table->bigInteger('valuation_quantity_milli')->default(0);
            $table->bigInteger('valuation_value_milli_iqd')->default(0);
            $table->bigInteger('minimum_milli')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('accounting_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->string('submission_hash', 64);
            $table->string('type', 30)->index();
            $table->string('title', 160);
            $table->string('supplier', 160)->nullable();
            $table->date('occurred_on')->index();
            $table->unsignedBigInteger('amount_iqd');
            $table->unsignedBigInteger('paid_iqd')->default(0);
            $table->foreignId('stock_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('quantity_milli')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignUuid('accounting_entry_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_iqd');
            $table->date('paid_on');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('cost_recipes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->json('parameters');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('cost_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->string('submission_hash', 64);
            $table->foreignId('cost_recipe_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 160)->nullable();
            $table->unsignedInteger('quantity');
            $table->bigInteger('total_milli_iqd');
            $table->json('snapshot');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('accounting_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('cost_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_of')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->string('type', 20);
            $table->bigInteger('quantity_milli');
            $table->bigInteger('value_milli_iqd');
            $table->bigInteger('basis_before_quantity_milli');
            $table->bigInteger('basis_before_value_milli_iqd');
            $table->bigInteger('balance_milli');
            $table->bigInteger('balance_value_milli_iqd');
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'cost_runs', 'cost_recipes', 'accounting_payments', 'accounting_entries', 'stock_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
