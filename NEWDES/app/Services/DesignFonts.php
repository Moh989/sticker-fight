<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class DesignFonts
{
    /** @return array<string, array{label: string, languages: array<int, string>, files: array<int, array<string, string>>}> */
    public static function definitions(): array
    {
        return config('design_fonts');
    }

    /** @return array<string, array{label: string, languages: array<int, string>}> */
    public static function choices(): array
    {
        return array_map(fn (array $font): array => ['label' => $font['label'], 'languages' => $font['languages'], 'preview_images' => $font['preview_images'] ?? []], self::available());
    }

    public static function available(): array
    {
        return array_filter(self::definitions(), fn (array $font): bool => empty($font['server_rendered']) || collect($font['files'])->every(fn (array $file): bool => Storage::disk('local')->exists('design-fonts/'.$file['file'])));
    }

    /** @return array<string, array{files: array<int, array<string, string>>}> */
    public static function freeze(array $document, string $orderId): array
    {
        $families = collect($document['sides'])->flatMap(fn (array $side) => collect($side['elements'])->where('type', 'text')->pluck('font'))->filter()->unique();
        $definitions = self::definitions();
        $result = [];
        foreach ($families as $family) {
            if (! isset($definitions[$family])) {
                throw new \RuntimeException('The design font is unavailable.');
            }
            foreach ($definitions[$family]['files'] as $file) {
                $bytes = empty($definitions[$family]['server_rendered']) ? file_get_contents(public_path('fonts/'.$file['file'])) : Storage::disk('local')->get('design-fonts/'.$file['file']);
                $checksum = hash('sha256', $bytes);
                $path = 'order-assets/'.$orderId.'/fonts/'.$checksum.'-'.$file['file'];
                Storage::disk('local')->put($path, $bytes);
                $result[$family]['files'][] = $file + ['path' => $path, 'sha256' => $checksum];
                $result[$family]['server_rendered'] = (bool) ($definitions[$family]['server_rendered'] ?? false);
            }
        }

        return $result;
    }
}
