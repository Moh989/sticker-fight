<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class NameFontPreview
{
    public function __construct(private NameTextRenderer $renderer) {}

    /** @return array<string, string> */
    public function images(array $document, array $fonts = []): array
    {
        $images = [];
        $definitions = DesignFonts::definitions();
        foreach ($document['sides'] as $side) {
            foreach ($side['elements'] as $element) {
                if ($element['type'] !== 'text' || ! ($fonts[$element['font']]['server_rendered'] ?? $definitions[$element['font']]['server_rendered'] ?? false)) {
                    continue;
                }
                $path = $this->imagePath($element, $fonts);
                $images[$element['id']] = 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($path));
            }
        }

        return $images;
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    public function bounds(array $element, array $fonts = []): array
    {
        $path = $this->imagePath($element, $fonts);
        $disk = Storage::disk('local');
        $metricsPath = dirname($path).'/bounds.json';
        if ($disk->exists($metricsPath)) {
            return json_decode($disk->get($metricsPath), true, 512, JSON_THROW_ON_ERROR);
        }
        $image = imagecreatefrompng($disk->path($path));
        $width = imagesx($image);
        $height = imagesy($image);
        $left = $width;
        $top = $height;
        $right = $bottom = -1;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ((imagecolorat($image, $x, $y) >> 24 & 127) < 120) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }
        unset($image);
        if ($right < $left || $bottom < $top) {
            throw new \RuntimeException('The name could not be measured.');
        }
        $bounds = ['x' => $left / $width * $element['width'], 'y' => $top / $height * $element['height'], 'width' => ($right - $left + 1) / $width * $element['width'], 'height' => ($bottom - $top + 1) / $height * $element['height']];
        $disk->put($metricsPath, json_encode($bounds, JSON_THROW_ON_ERROR));

        return $bounds;
    }

    private function imagePath(array $element, array $fonts): string
    {
        $definition = DesignFonts::definitions()[$element['font']];
        $files = $fonts[$element['font']]['files'] ?? $definition['files'];
        $hashes = [];
        foreach ($files as $file) {
            $bytes = isset($file['path']) ? Storage::disk('local')->get($file['path']) : (empty($definition['server_rendered']) ? file_get_contents(public_path('fonts/'.$file['file'])) : Storage::disk('local')->get('design-fonts/'.$file['file']));
            $checksum = hash('sha256', $bytes);
            if (isset($file['sha256']) && ! hash_equals($file['sha256'], $checksum)) {
                throw new \RuntimeException('Frozen font checksum mismatch.');
            }
            $hashes[] = $checksum;
        }
        $name = array_intersect_key($element, array_flip(['text', 'font', 'width', 'height', 'fontSize', 'color', 'align', 'fitToWidth']));
        $key = hash_hmac('sha256', json_encode([$name, $hashes], JSON_THROW_ON_ERROR), config('app.key'));
        $directory = 'name-previews/'.$key;
        $path = $directory.'/name.png';
        Cache::lock('name-preview-'.$key, 120)->block(65, function () use ($element, $fonts, $directory, $path): void {
            if (! Storage::disk('local')->exists($path)) {
                $this->renderer->render($element, $fonts, $directory);
            }
        });

        return $path;
    }
}
