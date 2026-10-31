<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class NameTextRenderer
{
    public function render(array $element, array $fonts, string $directory): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory($directory);
        $source = $directory.'/source.json';
        $disk->put($source, json_encode(['element' => $element, 'fonts' => $fonts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        try {
            $process = new Process([config('sticker.node'), base_path('scripts/render-name.mjs'), $disk->path($source), $disk->path($directory.'/name.png')], base_path(), array_filter(['CHROMIUM_EXECUTABLE' => config('sticker.chromium')]), null, 60);
            $process->mustRun();
            if (! $disk->exists($directory.'/name.png') || ! getimagesize($disk->path($directory.'/name.png'))) {
                throw new \RuntimeException('The name preview could not be rendered.');
            }
        } catch (\Throwable $exception) {
            $disk->deleteDirectory($directory);
            throw $exception;
        } finally {
            $disk->delete($source);
        }
    }
}
