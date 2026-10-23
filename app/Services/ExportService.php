<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\ProductionFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ExportService
{
    public function run(OrderItem $item): void
    {
        $item->load('order');
        $snapshot = $item->snapshot;
        $profile = $snapshot['print_profile'];
        if (! $profile) {
            throw new \RuntimeException('Print profile is missing.');
        }
        $spec = $snapshot + ['order_number' => $item->order->number, 'item_id' => $item->id, 'quantity' => $item->quantity, 'production_approved' => $item->order->status === 'approved'];
        $disk = Storage::disk('local');
        $run = bin2hex(random_bytes(8));
        $directory = 'production/'.$item->order_id.'/'.$item->id.'/'.$run;
        $disk->makeDirectory($directory);
        $specPath = $directory.'/source.json';
        $disk->put($specPath, json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $process = new Process([config('sticker.node'), base_path('scripts/render.mjs'), $disk->path($specPath), $disk->path($directory)], base_path(), array_filter(['CHROMIUM_EXECUTABLE' => config('sticker.chromium')]), null, 180);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $zip = new \ZipArchive;
        $zipPath = $directory.'/production.zip';
        if ($zip->open($disk->path($zipPath), \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP.');
        }
        foreach ($result['files'] as $f) {
            $zip->addFile($disk->path($directory.'/'.$f['name']), $f['name']);
        }$zip->close();
        $result['files'][] = ['side' => 'all', 'kind' => 'zip', 'name' => 'production.zip'];
        DB::transaction(function () use ($result, $item, $directory, $disk) {
            ProductionFile::where('order_item_id', $item->id)->delete();
            foreach ($result['files'] as $f) {
                $path = $directory.'/'.$f['name'];
                ProductionFile::create(['order_item_id' => $item->id, 'side' => $f['side'], 'kind' => $f['kind'], 'path' => $path, 'sha256' => hash_file('sha256', $disk->path($path))]);
            }$item->update(['export_status' => 'ready']);
        });
        $disk->delete($specPath);
    }
}
