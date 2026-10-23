<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Design;
use App\Models\DesignVersion;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderRetention
{
    public function expired(): Builder
    {
        return Order::where('created_at', '<', now()->subDays(config('sticker.order_retention_days')))->whereIn('status', ['delivered', 'cancelled', 'returned'])->whereHas('items', fn ($q) => $q->where('export_status', '!=', 'expired'));
    }

    public function archive(): int
    {
        $count = 0;
        $this->expired()->chunkById(100, function ($orders) use (&$count) {
            foreach ($orders as $order) {
                $disk = Storage::disk('local');
                if (! $disk->deleteDirectory('order-assets/'.$order->id) || ! $disk->deleteDirectory('production/'.$order->id)) {
                    throw new \RuntimeException('Could not delete expired order files.');
                }DB::transaction(function () use ($order) {
                    $blank = ['version' => 1, 'sides' => ['front' => ['template_version_id' => null, 'background' => '#18181E', 'elements' => []], 'back' => ['template_version_id' => null, 'background' => '#18181E', 'elements' => []]]];
                    foreach ($order->items as $item) {
                        $snapshot = collect($item->snapshot)->except(['document', 'assets', 'art_assets'])->all();
                        $snapshot['name'] = 'تصميم مؤرشف';
                        $snapshot['retention_expired_at'] = now()->toIso8601String();
                        DB::table('order_items')->where('id', $item->id)->update(['snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'export_status' => 'expired']);
                        ProductionFile::where('order_item_id', $item->id)->delete();
                    }
                    foreach ($order->items as $item) {
                        if (OrderItem::where('design_version_id', $item->design_version_id)->where('export_status', '!=', 'expired')->exists() || CartItem::where('design_version_id', $item->design_version_id)->exists()) {
                            continue;
                        }$version = DesignVersion::find($item->design_version_id);
                        DB::table('design_versions')->where('id', $version->id)->update(['document' => json_encode($blank)]);
                        $design = Design::find($version->design_id);
                        if ($design && $design->updated_at->lt(now()->subDays(config('sticker.retention_days'))) && ! CartItem::whereIn('design_version_id', $design->versions()->select('id'))->exists() && ! OrderItem::whereIn('design_version_id', $design->versions()->select('id'))->where('export_status', '!=', 'expired')->exists()) {
                            DB::table('design_versions')->where('design_id', $design->id)->update(['document' => json_encode($blank)]);
                            DB::table('designs')->where('id', $design->id)->update(['name' => 'مسودة مؤرشفة', 'document' => json_encode($blank)]);
                        }
                    }
                    $address = $order->address;
                    foreach (['city', 'district', 'address', 'landmark'] as $field) {
                        $address[$field] = '';
                    }$order->update(['address' => $address, 'notes' => null]);
                    Customer::whereKey($order->customer_id)->update(['name' => 'زبون مؤرشف', 'phone' => 'deleted', 'alternate_phone' => null]);
                });
                $count++;
            }
        });

        return $count;
    }
}
