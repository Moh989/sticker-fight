<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\TemplateVersion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DesignValidator
{
    const ARTS = ['afterhours', 'baghdad', 'orbit', 'nope', 'checker', 'bloom', 'tiger', 'minimal', 'name-card'];

    public function validate(array $data): array
    {
        $validated = Validator::make($data, [
            'name' => 'required|string|max:100', 'revision' => 'required|integer|min:0',
            'options' => 'required|array:mode,printed_side,transparent_side,material_id,transparent_material_id,finish_id,product_id',
            'options.mode' => 'required|in:single,double,mixed', 'options.printed_side' => 'required|in:front,back', 'options.transparent_side' => 'required|in:front,back',
            'options.material_id' => 'nullable|integer|exists:materials,id', 'options.transparent_material_id' => 'nullable|integer|exists:materials,id', 'options.finish_id' => 'nullable|integer|exists:finishes,id', 'options.product_id' => 'prohibited',
            'document' => 'required|array:version,sides', 'document.version' => 'required|integer|in:1', 'document.sides' => 'required|array:front,back',
            'document.sides.front' => 'required|array:template_version_id,background,elements', 'document.sides.back' => 'required|array:template_version_id,background,elements',
            'document.sides.*.template_version_id' => 'nullable|integer|exists:template_versions,id', 'document.sides.*.background' => 'present|nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'document.sides.*.elements' => 'present|array|max:30', 'document.sides.*.elements.*' => 'required|array:id,type,x,y,width,height,rotation,text,font,fontSize,color,align,asset_id,art_key,locked,fit',
            'document.sides.*.elements.*.id' => 'required|string|regex:/^[a-zA-Z0-9_-]{1,60}$/',
            'document.sides.*.elements.*.type' => 'required|in:image,text', 'document.sides.*.elements.*.x' => 'required|numeric|between:-200,200', 'document.sides.*.elements.*.y' => 'required|numeric|between:-200,200',
            'document.sides.*.elements.*.width' => 'required|numeric|between:0.2,500', 'document.sides.*.elements.*.height' => 'required|numeric|between:0.2,500', 'document.sides.*.elements.*.rotation' => 'required|numeric|between:-360,360',
            'document.sides.*.elements.*.text' => 'nullable|string|max:500', 'document.sides.*.elements.*.font' => 'nullable|in:Cairo,Bebas Neue', 'document.sides.*.elements.*.fontSize' => 'nullable|numeric|between:1,30', 'document.sides.*.elements.*.color' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'document.sides.*.elements.*.align' => 'nullable|in:start,middle,end', 'document.sides.*.elements.*.asset_id' => 'nullable|uuid|exists:assets,id', 'document.sides.*.elements.*.art_key' => 'nullable|in:'.implode(',', self::ARTS), 'document.sides.*.elements.*.locked' => 'required|boolean', 'document.sides.*.elements.*.fit' => 'nullable|in:cover,contain',
        ], ['options.product_id.prohibited' => 'التصاميم الجاهزة ثابتة وتُطلب من صفحة المنتج.', 'document.sides.*.elements.*.type.in' => 'المحرر مخصص لصورك ونصوصك. التصاميم الجاهزة ثابتة وتُطلب من صفحة المنتج.'])->validate();
        foreach ($validated['document']['sides'] as $side => $doc) {
            if ($doc['template_version_id']) {
                $v = TemplateVersion::with('template')->findOrFail($doc['template_version_id']);
                if ($v->template->side !== $side) {
                    throw ValidationException::withMessages(['template' => 'القالب لا يطابق الجهة المختارة.']);
                }
            }
            $ids = [];
            foreach ($doc['elements'] as $e) {
                if (in_array($e['id'], $ids)) {
                    throw ValidationException::withMessages(['elements' => 'معرّف الطبقة مكرر.']);
                }$ids[] = $e['id'];
                if ($e['type'] === 'image') {
                    if (empty($e['asset_id'])) {
                        throw ValidationException::withMessages(['image' => 'اختر صورة مرفوعة.']);
                    }
                    $asset = Asset::findOrFail($e['asset_id']);
                    Ownership::check($asset);
                    if ($asset->source === Asset::SOURCE_CATALOG) {
                        throw ValidationException::withMessages(['image' => 'صورة الكتالوج ثابتة ولا تقبل التعديل. ارفع صورتك الخاصة للمحرر.']);
                    }
                }
                if ($e['type'] === 'art' && empty($e['art_key'])) {
                    throw ValidationException::withMessages(['art' => 'اختر رسماً صالحاً.']);
                }
                if ($e['type'] === 'text' && preg_match('/[\x{0600}-\x{06ff}]/u', $e['text'] ?? '') && ($e['font'] ?? null) !== 'Cairo') {
                    throw ValidationException::withMessages(['font' => 'اختر Cairo للنص العربي.']);
                }
                if ($e['type'] === 'text' && (! isset($e['text'],$e['font'],$e['fontSize'],$e['color'],$e['align']))) {
                    throw ValidationException::withMessages(['text' => 'إعدادات النص ناقصة.']);
                }
            }
            if ($validated['options']['mode'] === 'mixed' && $side === $validated['options']['transparent_side'] && count($doc['elements'])) {
                throw ValidationException::withMessages(['transparent' => 'الجهة الشفافة غير مطبوعة ولا تقبل رسومات.']);
            }
        }

        return $validated;
    }

    public function assertArtwork(array $document, array $options): void
    {
        if ((isset($options['product_id']) || collect($document['sides'])->contains(fn ($side) => collect($side['elements'])->contains(fn ($element) => $element['type'] === 'art'))) && empty($options['ready_locked'])) {
            throw ValidationException::withMessages(['artwork' => 'هذا تصميم جاهز ثابت. أعد اختيار خيارات الطلب من صفحة المنتج.']);
        }
        $sides = $options['mode'] === 'single' ? [$options['printed_side']] : ['front', 'back'];
        foreach ($sides as $side) {
            if ($options['mode'] === 'mixed' && $side === $options['transparent_side']) {
                continue;
            }$drawing = $document['sides'][$side];
            if (($drawing['background'] ?? null) === null && empty($drawing['elements'])) {
                throw ValidationException::withMessages(['artwork' => 'أضف صورة أو نصاً أو لون خلفية للجهة المطبوعة قبل الطلب.']);
            }
        }
    }

    public function templates(array $document): array
    {
        $out = [];
        foreach ($document['sides'] as $side => $d) {
            if ($d['template_version_id']) {
                $v = TemplateVersion::with('template')->findOrFail($d['template_version_id']);
                $out[$side] = ['id' => $v->id, 'name' => $v->template->name, 'side' => $v->template->side, 'version' => $v->version, 'geometry' => $v->geometry, 'compatibility' => $v->compatibility, 'approved_at' => $v->approved_at?->toIso8601String(), 'sample_reference' => $v->sample_reference];
            }
        }

        return $out;
    }
}
