<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_templates', function (Blueprint $table): void {
            $table->string('kind', 20)->nullable()->index();
        });
        if (! DB::table('card_templates')->exists()) {
            return;
        }
        DB::transaction(function (): void {
            foreach (DB::table('card_templates')->where('side', 'front')->get() as $template) {
                $kind = match (true) {
                    (bool) preg_match('/بدون\s+(?:شريحة|شريحه)/u', $template->name) => 'no_chip',
                    (bool) preg_match('/(?:شريحة|شريحه)\s+(?:صغيرة|صغيره)/u', $template->name) => 'small_chip',
                    default => null,
                };
                DB::table('card_templates')->where('id', $template->id)->update(['kind' => $kind, ...($kind === null ? ['active' => false] : [])]);
            }
            $small = DB::table('card_templates')->where('side', 'front')->where('kind', 'small_chip')->first();
            $source = $small ? DB::table('template_versions')->where('card_template_id', $small->id)->orderByRaw('approved_at IS NULL')->orderByDesc('version')->first() : null;
            $geometry = $source ? json_decode($source->geometry, true) : ['card_width' => 85.6, 'card_height' => 53.98, 'width' => 85.6, 'height' => 53.98, 'radius' => 3.18, 'bleed' => 2, 'safe' => 3, 'holes' => []];
            $compatibility = $source ? json_decode($source->compatibility, true) : ['modes' => ['single', 'double', 'mixed'], 'material_ids' => DB::table('materials')->pluck('id')->all(), 'finish_ids' => DB::table('finishes')->pluck('id')->all()];
            foreach (['small_chip' => 'قالب مع شريحة صغيرة', 'no_chip' => 'قالب بدون شريحة'] as $kind => $name) {
                if (DB::table('card_templates')->where('side', 'front')->where('kind', $kind)->exists()) {
                    continue;
                }
                $id = DB::table('card_templates')->insertGetId(['name' => $name, 'side' => 'front', 'kind' => $kind, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $draftGeometry = [...$geometry, 'holes' => $kind === 'no_chip' ? [] : [['x' => 10, 'y' => 19, 'width' => 11, 'height' => 9, 'radius' => 1.4]]];
                DB::table('template_versions')->insert(['card_template_id' => $id, 'version' => 1, 'geometry' => json_encode($draftGeometry), 'compatibility' => json_encode($compatibility), 'approved_at' => null, 'approved_by' => null, 'sample_reference' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('card_templates', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
