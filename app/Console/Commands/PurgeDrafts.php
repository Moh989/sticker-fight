<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\DesignVersion;
use App\Models\OrderItem;
use App\Services\OrderRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeDrafts extends Command
{
    protected $signature = 'sticker:purge {--force : Apply deletion; without this flag show the count only} {--orders : Also apply the configured retention policy to terminal orders}';

    protected $description = 'Remove expired unreferenced drafts and uploads; ordered designs are retained.';

    public function handle(): int
    {
        $retention = app(OrderRetention::class);
        if ($this->option('orders')) {
            if ($this->option('force')) {
                $this->info('Archived expired orders: '.$retention->archive());
            } else {
                $this->info('Terminal orders eligible for archival: '.$retention->expired()->count());
            }
        }$cutoff = now()->subDays(config('sticker.retention_days'));
        $ids = Design::where('updated_at', '<', $cutoff)->whereDoesntHave('versions', fn ($v) => $v->whereIn('id', OrderItem::select('design_version_id'))->orWhereIn('id', CartItem::select('design_version_id')))->pluck('id');
        if (! $this->option('force')) {
            $this->info('Expired unreferenced drafts: '.$ids->count().'. Use --force to apply the configured policy.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($ids) {
            DB::table('design_versions')->whereIn('design_id', $ids)->delete();
            DB::table('designs')->whereIn('id', $ids)->delete();
        });
        $referenced = [];
        DesignVersion::select('id', 'document')->chunkById(200, function ($versions) use (&$referenced) {
            foreach ($versions as $v) {
                foreach ($v->document['sides'] as $side) {
                    foreach ($side['elements'] as $e) {
                        if (isset($e['asset_id'])) {
                            $referenced[$e['asset_id']] = true;
                        }
                    }
                }
            }
        });
        $deleted = 0;
        Asset::where('updated_at', '<', $cutoff)->chunkById(100, function ($assets) use ($referenced, &$deleted) {
            foreach ($assets as $asset) {
                if (isset($referenced[$asset->id])) {
                    continue;
                }Storage::disk('local')->delete($asset->path);
                $asset->delete();
                $deleted++;
            }
        });
        $this->info('Purged drafts: '.$ids->count().'; unreferenced uploads: '.$deleted.'. Frozen order assets were retained.');

        return self::SUCCESS;
    }
}
