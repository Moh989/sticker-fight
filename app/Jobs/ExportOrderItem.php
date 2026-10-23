<?php

namespace App\Jobs;

use App\Models\ExportRun;
use App\Models\OrderItem;
use App\Services\ExportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportOrderItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 200;

    public int $uniqueFor = 900;

    public function __construct(public int $itemId) {}

    public function uniqueId(): string
    {
        return 'item-'.$this->itemId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ExportService $export): void
    {
        $item = OrderItem::findOrFail($this->itemId);
        if ($item->export_status === 'expired') {
            return;
        }$item->update(['export_status' => 'processing']);
        $run = ExportRun::create(['order_item_id' => $item->id, 'status' => 'processing']);
        try {
            $export->run($item);
            $run->update(['status' => 'ready', 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error' => 'فشل التصدير. راجع سجل الخادم ورقم التشغيل '.$run->id, 'finished_at' => now()]);
            $item->update(['export_status' => 'failed']);
            report($e);
            throw $e;
        }
    }

    public function failed(?\Throwable $e): void
    {
        OrderItem::whereKey($this->itemId)->update(['export_status' => 'failed']);
    }
}
