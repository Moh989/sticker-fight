<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('assets', 'source')) {
            Schema::table('assets', fn (Blueprint $table) => $table->string('source', 16)->default('upload'));
        }
        Schema::table('assets', function (Blueprint $table) {
            $table->unique(['guest_session_id', 'sha256', 'source']);
            $table->dropUnique(['guest_session_id', 'sha256']);
        });

        DB::table('assets')->whereIn('sha256', DB::table('catalog_assets')->select('sha256'))->update(['source' => 'catalog']);
    }

    public function down(): void
    {
        if (DB::table('assets')->select('guest_session_id', 'sha256')->groupBy('guest_session_id', 'sha256')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot remove asset sources while distinct uploads and catalogue copies share a checksum. Preserve both assets before rollback.');
        }
        Schema::table('assets', function (Blueprint $table) {
            $table->unique(['guest_session_id', 'sha256']);
            $table->dropUnique(['guest_session_id', 'sha256', 'source']);
            $table->dropColumn('source');
        });
    }
};
