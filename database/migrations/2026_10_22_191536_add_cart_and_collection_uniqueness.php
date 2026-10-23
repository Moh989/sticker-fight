<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', fn (Blueprint $t) => $t->unique(['guest_session_id', 'design_version_id']));
        Schema::table('collections', fn (Blueprint $t) => $t->unique(['order_id', 'method', 'reference']));
    }

    public function down(): void
    {
        Schema::table('cart_items', fn (Blueprint $t) => $t->dropUnique(['guest_session_id', 'design_version_id']));
        Schema::table('collections', fn (Blueprint $t) => $t->dropUnique(['order_id', 'method', 'reference']));
    }
};
