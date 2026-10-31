<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReadyNamePersonalization
{
    public function __construct(private NameFontPreview $preview) {}

    public const DEFAULT_LAYOUT = ['x' => 12, 'y' => 33, 'width' => 61.6, 'height' => 12, 'font_size' => 4.5, 'color' => '#30262B'];

    /** @return array{x: float, y: float, width: float, height: float, font_size: float, color: string} */
    public function adminLayout(Request $request, Product $product): array
    {
        $data = $request->validate(['name_x' => 'nullable|numeric|between:0,85.6', 'name_y' => 'nullable|numeric|between:0,53.98', 'name_width' => 'nullable|numeric|between:15,85.6', 'name_height' => 'nullable|numeric|between:5,30', 'name_font_size' => 'nullable|numeric|between:2,12', 'name_color' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/']);
        $layout = $product->personalization_layout ?? self::DEFAULT_LAYOUT;
        foreach (self::DEFAULT_LAYOUT as $key => $default) {
            $value = $data['name_'.$key] ?? $layout[$key] ?? $default;
            $layout[$key] = $key === 'color' ? $value : (float) $value;
        }
        if ($layout['x'] + $layout['width'] > 85.6 || $layout['y'] + $layout['height'] > 53.98) {
            throw ValidationException::withMessages(['name_width' => __('مساحة الاسم يجب أن تبقى داخل البطاقة.')]);
        }

        return $layout;
    }

    public function configuration(Product $product, array $input, array $templates, bool $previewOnly = false): ?array
    {
        $data = Validator::make($input, ['personalization_name' => $product->personalization_enabled ? 'required|string|max:30' : 'prohibited', 'personalization_font' => $product->personalization_enabled ? 'required|string|max:50' : 'prohibited', 'personalization_x' => $product->personalization_enabled ? 'nullable|required_with:personalization_y|numeric|between:-85.6,85.6' : 'prohibited', 'personalization_y' => $product->personalization_enabled ? 'nullable|required_with:personalization_x|numeric|between:-53.98,53.98' : 'prohibited'], ['personalization_name.required' => __('اكتب الاسم اللي تريد ينطبع على التصميم.'), 'personalization_name.max' => __('الاسم حتى 30 حرفاً حتى يبقى واضحاً على البطاقة.'), 'personalization_font.required' => __('اختار خط الاسم.')])->validate();
        if (! $product->personalization_enabled) {
            return null;
        }
        $name = preg_replace('/\p{Zs}+/u', ' ', trim($data['personalization_name']));
        if (! preg_match('/^[\p{Arabic}\p{Latin}\p{M}\p{N}\p{Zs}.\x{0027}\x{2019}-]+$/u', $name) || ! preg_match('/\p{L}/u', $name)) {
            throw ValidationException::withMessages(['personalization_name' => __('اكتب اسماً بالعربي أو الإنجليزي، بدون رموز أو أسطر جديدة.')]);
        }
        $language = preg_match('/\p{Arabic}/u', $name) ? 'ar' : 'en';
        $font = DesignFonts::available()[$data['personalization_font']] ?? null;
        if (! $font || ! in_array($language, $font['languages'], true)) {
            throw ValidationException::withMessages(['personalization_font' => __('اختار خطاً من قائمة لغة الاسم.')]);
        }
        $layout = $product->personalization_layout ?? self::DEFAULT_LAYOUT;
        $personalization = ['name' => $name, 'font' => $data['personalization_font'], 'language' => $language, 'layout' => $layout];
        $manual = isset($data['personalization_x'], $data['personalization_y']);
        $bounds = $manual ? $this->preview->bounds($this->element($personalization, 'front')) : ['x' => 0, 'y' => 0, 'width' => $layout['width'], 'height' => $layout['height']];
        if ($manual) {
            $layout['x'] = round((float) $data['personalization_x'], 3);
            $layout['y'] = round((float) $data['personalization_y'], 3);
        }
        $left = $layout['x'] + $bounds['x'];
        $top = $layout['y'] + $bounds['y'];
        $error = null;
        foreach ($templates as $template) {
            $geometry = $template['geometry'];
            $safe = $geometry['safe'];
            if ($left < $safe || $top < $safe || $left + $bounds['width'] > $geometry['width'] - $safe || $top + $bounds['height'] > $geometry['height'] - $safe) {
                $error = __('خلّي الاسم داخل حدود الأمان حتى ينطبع كامل.');
            }
            foreach ($geometry['holes'] as $hole) {
                if ($left < $hole['x'] + $hole['width'] + $safe && $left + $bounds['width'] > $hole['x'] - $safe && $top < $hole['y'] + $hole['height'] + $safe && $top + $bounds['height'] > $hole['y'] - $safe) {
                    $error = __('حرّك الاسم بعيد عن الشريحة أو الفتحات.');
                }
            }
        }

        if ($error !== null && ! $previewOnly) {
            throw ValidationException::withMessages([$manual ? 'personalization_x' : 'personalization_name' => $error]);
        }
        $personalization['layout'] = $layout;
        if ($manual) {
            $personalization += ['positioned_by_customer' => true, 'bounds' => $bounds];
        }
        if ($manual || $error !== null) {
            $personalization += ['placement_valid' => $error === null, 'placement_message' => $error];
        }

        return $personalization;
    }

    public function element(array $personalization, string $side): array
    {
        $layout = $personalization['layout'];

        return ['id' => 'ready-name-'.$side, 'type' => 'text', 'x' => $layout['x'], 'y' => $layout['y'], 'width' => $layout['width'], 'height' => $layout['height'], 'rotation' => 0, 'text' => $personalization['name'], 'font' => $personalization['font'], 'fontSize' => $layout['font_size'], 'color' => $layout['color'], 'align' => 'middle', 'locked' => true, 'fitToWidth' => true];
    }
}
