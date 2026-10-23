<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CatalogAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CatalogService
{
    public function publish(Asset $asset): CatalogAsset
    {
        $disk = Storage::disk('local');
        $id = (string) Str::uuid();
        $path = 'catalog/'.$id.'/original.png';
        $disk->put($path, $disk->get($asset->path));
        $source = imagecreatefrompng($disk->path($path));
        $target = imagecreatetruecolor(856, 540);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        $ratio = max(856 / $asset->width, 540 / $asset->height);
        $sw = 856 / $ratio;
        $sh = 540 / $ratio;
        imagecopyresampled($target, $source, 0, 0, (int) (($asset->width - $sw) / 2), (int) (($asset->height - $sh) / 2), 856, 540, (int) $sw, (int) $sh);
        ob_start();
        imagewebp($target, null, 84);
        $thumbnail = ob_get_clean();
        unset($source, $target);
        $thumb = 'catalog/'.$id.'/thumbnail.webp';
        $disk->put($thumb, $thumbnail);

        return CatalogAsset::create(['id' => $id, 'path' => $path, 'thumbnail_path' => $thumb, 'width' => $asset->width, 'height' => $asset->height, 'sha256' => $asset->sha256]);
    }

    public function guestAsset(CatalogAsset $catalog): Asset
    {
        $existing = Asset::where('guest_session_id', session('guest_id'))->where('sha256', $catalog->sha256)->where('source', Asset::SOURCE_CATALOG)->first();
        if ($existing) {
            return $existing;
        }
        $id = (string) Str::uuid();
        $path = 'assets/'.session('guest_id').'/'.$id.'.png';
        $disk = Storage::disk('local');
        $disk->put($path, $disk->get($catalog->path));

        return Asset::create(['id' => $id, 'guest_session_id' => session('guest_id'), 'user_id' => auth()->id(), 'source' => Asset::SOURCE_CATALOG, 'path' => $path, 'mime' => 'image/png', 'width' => $catalog->width, 'height' => $catalog->height, 'bytes' => $disk->size($path), 'sha256' => $catalog->sha256]);
    }

    public function previewPath(CatalogAsset $catalog): string
    {
        $disk = Storage::disk('local');
        $path = 'catalog/'.$catalog->id.'/preview.webp';
        if (! $disk->exists($path)) {
            $source = imagecreatefrompng($disk->path($catalog->path));
            $scale = min(1, 1200 / max($catalog->width, $catalog->height));
            $width = max(1, (int) round($catalog->width * $scale));
            $height = max(1, (int) round($catalog->height * $scale));
            $target = imagecreatetruecolor($width, $height);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $catalog->width, $catalog->height);
            ob_start();
            imagewebp($target, null, 84);
            $contents = ob_get_clean();
            unset($source, $target);
            $disk->put($path, $contents);
        }

        return $path;
    }
}
