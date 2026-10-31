<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('customer');
        });
        Schema::create('guest_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('recovery_hash', 64)->unique();
            $t->timestamp('last_seen_at');
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('phone');
            $t->string('alternate_phone')->nullable();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->string('art_key')->nullable();
            $t->unsignedBigInteger('price_iqd')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('materials', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->unique();
            $t->boolean('transparent')->default(false);
            $t->boolean('active')->default(false);
            $t->unsignedBigInteger('surcharge_iqd')->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('finishes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('active')->default(false);
            $t->unsignedBigInteger('surcharge_iqd')->default(0);
            $t->timestamps();
        });
        Schema::create('card_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('side');
            $t->boolean('active')->default(false);
            $t->timestamps();
        });
        Schema::create('template_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('card_template_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('geometry');
            $t->json('compatibility');
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('sample_reference')->nullable();
            $t->timestamps();
            $t->unique(['card_template_id', 'version']);
        });
        Schema::create('designs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('guest_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->unsignedInteger('revision')->default(0);
            $t->json('document');
            $t->json('options');
            $t->timestamps();
        });
        Schema::create('design_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('design_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->json('document');
            $t->json('options');
            $t->json('templates');
            $t->timestamps();
            $t->unique(['design_id', 'revision']);
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('guest_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('path');
            $t->string('mime');
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->unsignedBigInteger('bytes');
            $t->string('sha256', 64);
            $t->timestamps();
            $t->unique(['guest_session_id', 'sha256']);
        });
        Schema::create('cart_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('guest_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('design_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity')->default(1);
            $t->timestamps();
        });
        Schema::create('governorates', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('areas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('shipping_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('area_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('fee_iqd')->nullable();
            $t->string('estimated_time')->nullable();
            $t->boolean('active')->default(false);
            $t->timestamps();
        });
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('type')->default('fixed');
            $t->unsignedInteger('value');
            $t->unsignedBigInteger('minimum_iqd')->default(0);
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('used')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('active')->default(false);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number')->unique();
            $t->string('tracking_hash', 64)->unique();
            $t->foreignUuid('guest_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id');
            $t->string('status')->default('pending_confirmation');
            $t->string('financial_status')->default('unpaid');
            $t->json('address');
            $t->json('totals');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['guest_session_id', 'request_id']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('design_version_id')->constrained()->restrictOnDelete();
            $t->json('snapshot');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_iqd');
            $t->string('export_status')->default('pending');
            $t->timestamps();
        });
        Schema::create('production_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $t->string('side');
            $t->string('kind');
            $t->string('path');
            $t->string('sha256', 64);
            $t->timestamps();
        });
        Schema::create('export_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('queued');
            $t->text('error')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('collections', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_iqd');
            $t->string('method');
            $t->string('reference')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
        Schema::create('content_pages', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('title');
            $t->longText('body')->nullable();
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('subject_type');
            $t->string('subject_id');
            $t->json('changes')->nullable();
            $t->timestamps();
        });
        Schema::create('review_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('guest_session_id')->constrained()->restrictOnDelete();
            $t->string('phone')->nullable();
            $t->text('description');
            $t->string('status')->default('new');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['review_requests', 'audit_logs', 'content_pages', 'settings', 'collections', 'export_runs', 'production_files', 'order_items', 'orders', 'coupons', 'shipping_rates', 'areas', 'governorates', 'cart_items', 'assets', 'design_versions', 'designs', 'template_versions', 'card_templates', 'finishes', 'materials', 'products', 'categories', 'customers', 'guest_sessions'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
