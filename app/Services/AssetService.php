<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssetService
{
    public function upload(UploadedFile $file): Asset
    {
        if ($file->getSize() > 12 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'الحد الأعلى للصورة 12 ميغابايت.']);
        }
        $info = @getimagesize($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! $info || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp']) || $info[0] * $info[1] > 32000000 || max($info[0], $info[1]) > 12000) {
            throw ValidationException::withMessages(['file' => 'ارفع JPG أو PNG أو WebP حتى 32 مليون بكسل. صدّر HEIC كـJPEG أولاً.']);
        }
        $limit = ini_get('memory_limit');
        if ($limit !== '-1') {
            $suffix = strtolower(substr($limit, -1));
            $available = (int) $limit * match ($suffix) {
                'g' => 1073741824,'m' => 1048576,'k' => 1024,default => 1
            };
            if ($available < $info[0] * $info[1] * 10 + memory_get_usage(true)) {
                throw ValidationException::withMessages(['file' => 'الصورة تتجاوز قدرة المعالجة الحالية. صدّر نسخة أصغر وحاول مجدداً.']);
            }
        }$image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),'image/png' => @imagecreatefrompng($file->getRealPath()),'image/webp' => @imagecreatefromwebp($file->getRealPath())
        };
        if (! $image) {
            throw ValidationException::withMessages(['file' => 'تعذر قراءة الصورة.']);
        }
        if ($mime === 'image/jpeg') {
            $exif = @exif_read_data($file->getRealPath());
            $o = $exif['Orientation'] ?? 1;
            if (in_array($o, [2, 4, 5, 7])) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }$angle = match ($o) {
                3,4 => 180,5,6 => -90,7,8 => 90,default => 0
            };
            if ($angle) {
                $image = imagerotate($image, $angle, 0);
            }
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 6);
        $bytes = ob_get_clean();
        $w = imagesx($image);
        $h = imagesy($image);
        unset($image);
        $hash = hash('sha256', $bytes);
        $existing = Asset::where('guest_session_id', session('guest_id'))->where('sha256', $hash)->where('source', Asset::SOURCE_UPLOAD)->first();
        if ($existing) {
            return $existing;
        }
        $id = (string) Str::uuid();
        $path = 'assets/'.session('guest_id').'/'.$id.'.png';
        Storage::disk('local')->put($path, $bytes);

        return Asset::create(['id' => $id, 'guest_session_id' => session('guest_id'), 'user_id' => auth()->id(), 'source' => Asset::SOURCE_UPLOAD, 'path' => $path, 'mime' => 'image/png', 'width' => $w, 'height' => $h, 'bytes' => strlen($bytes), 'sha256' => $hash]);
    }
}
