<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Design;
use App\Models\DesignVersion;
use App\Models\Finish;
use App\Models\GuestSession;
use App\Models\Material;
use App\Models\Product;
use App\Models\TemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReadyProductService
{
    public function __construct(private PricingService $pricing, private DesignValidator $validator, private CatalogService $catalog) {}

    public function choices(): array
    {
        return [
            'templates' => TemplateVersion::with('template')->whereNotNull('approved_at')->whereHas('template', fn ($query) => $query->where('active', true))->orderByDesc('version')->get()->map(fn ($version) => [
                'id' => $version->id, 'name' => $version->template->name, 'side' => $version->template->side, 'version' => $version->version, 'geometry' => $version->geometry, 'compatibility' => $version->compatibility,
            ]),
            'materials' => Material::where('active', true)->orderBy('surcharge_iqd')->orderBy('id')->get(),
            'finishes' => Finish::where('active', true)->orderBy('surcharge_iqd')->orderBy('id')->get(),
        ];
    }

    public function configuration(Product $product, array $input): array
    {
        abort_unless($product->active, 404);
        $data = Validator::make($input, [
            'mode' => 'required|in:single,double,mixed', 'printed_side' => 'sometimes|in:front,back', 'transparent_side' => 'sometimes|in:front,back',
            'material_id' => 'nullable|integer|exists:materials,id', 'transparent_material_id' => 'nullable|integer|exists:materials,id', 'finish_id' => 'nullable|integer|exists:finishes,id',
            'front_template_id' => 'nullable|integer|exists:template_versions,id', 'back_template_id' => 'nullable|integer|exists:template_versions,id', 'quantity' => 'required|integer|between:1,100',
            'document' => 'prohibited', 'elements' => 'prohibited', 'assets' => 'prohibited', 'options' => 'prohibited',
        ])->validate();
        if (! $product->catalogAsset && ! in_array($product->art_key, DesignValidator::ARTS, true)) {
            throw ValidationException::withMessages(['product' => 'صورة هذا التصميم غير متاحة حالياً.']);
        }
        $options = [
            'mode' => $data['mode'], 'printed_side' => $data['printed_side'] ?? 'front', 'transparent_side' => $data['transparent_side'] ?? 'front',
            'material_id' => isset($data['material_id']) ? (int) $data['material_id'] : null,
            'transparent_material_id' => $data['mode'] === 'mixed' && isset($data['transparent_material_id']) ? (int) $data['transparent_material_id'] : null,
            'finish_id' => isset($data['finish_id']) ? (int) $data['finish_id'] : null, 'product_id' => $product->id, 'ready_locked' => true,
        ];
        $document = ['version' => 1, 'sides' => ['front' => ['template_version_id' => null, 'background' => null, 'elements' => []], 'back' => ['template_version_id' => null, 'background' => null, 'elements' => []]]];
        $sides = $options['mode'] === 'single' ? [$options['printed_side']] : ['front', 'back'];
        foreach ($sides as $side) {
            $field = $side.'_template_id';
            if (empty($data[$field])) {
                throw ValidationException::withMessages([$field => $side === 'front' ? 'اختار قالب الجهة الأمامية المطابق لبطاقتك.' : 'اختار قالب الجهة الخلفية المطابق لبطاقتك.']);
            }
            $document['sides'][$side]['template_version_id'] = (int) $data[$field];
        }
        $templates = $this->validator->templates($document);
        $options['material_id'] ??= $this->defaultMaterial($options, $templates, false);
        if ($options['material_id'] === null) {
            throw ValidationException::withMessages(['material_id' => __('لا توجد خامة طباعة متوافقة مع القوالب المختارة حالياً.')]);
        }
        if ($options['mode'] === 'mixed') {
            $options['transparent_material_id'] ??= $this->defaultMaterial($options, $templates, true);
            if ($options['transparent_material_id'] === null) {
                throw ValidationException::withMessages(['transparent_material_id' => __('لا توجد خامة شفافة متوافقة مع القالب المختار حالياً.')]);
            }
        }
        $price = $this->pricing->quote($options, $templates, (int) $data['quantity']);

        return ['options' => $options, 'templates' => $templates, 'quantity' => (int) $data['quantity'], 'price' => $price];
    }

    private function defaultMaterial(array $options, array $templates, bool $transparent): ?int
    {
        $allowed = null;
        foreach ($templates as $side => $template) {
            if (($options['mode'] === 'mixed' && $options['transparent_side'] === $side) !== $transparent) {
                continue;
            }
            $ids = $template['compatibility']['material_ids'] ?? [];
            $allowed = $allowed === null ? $ids : array_intersect($allowed, $ids);
        }

        return Material::where('active', true)->where('transparent', $transparent)->whereIn('id', $allowed ?? [])->orderBy('surcharge_iqd')->orderBy('id')->value('id');
    }

    public function document(Product $product, array $configuration, ?string $assetId = null): array
    {
        $document = ['version' => 1, 'sides' => ['front' => ['template_version_id' => null, 'background' => null, 'elements' => []], 'back' => ['template_version_id' => null, 'background' => null, 'elements' => []]]];
        foreach ($configuration['templates'] as $side => $template) {
            $geometry = $template['geometry'];
            $document['sides'][$side]['template_version_id'] = $template['id'];
            if ($configuration['options']['mode'] === 'mixed' && $configuration['options']['transparent_side'] === $side) {
                continue;
            }
            $element = [
                'id' => 'ready-'.$side, 'type' => $product->catalogAsset ? 'image' : 'art',
                'x' => -$geometry['bleed'], 'y' => -$geometry['bleed'], 'width' => $geometry['width'] + 2 * $geometry['bleed'], 'height' => $geometry['height'] + 2 * $geometry['bleed'],
                'rotation' => 0, 'fit' => 'cover', 'locked' => true,
            ];
            if ($product->catalogAsset) {
                $element['asset_id'] = $assetId ?? $product->catalog_asset_id;
            } else {
                $element['art_key'] = $product->art_key;
            }
            $document['sides'][$side]['elements'][] = $element;
        }

        return $document;
    }

    public function addToCart(Product $product, array $input): CartItem
    {
        $request = Validator::make($input, ['request_id' => 'required|uuid', 'cart_item_id' => 'nullable|uuid'])->validate();
        $configuration = $this->configuration($product, $input);

        return DB::transaction(function () use ($product, $request, $configuration) {
            GuestSession::whereKey(session('guest_id'))->lockForUpdate()->firstOrFail();
            $previous = empty($request['cart_item_id']) ? null : CartItem::whereKey($request['cart_item_id'])->where('guest_session_id', session('guest_id'))->where('product_id', $product->id)->firstOrFail();
            $design = Design::find($request['request_id']);
            if ($design) {
                Ownership::check($design);
                $templateIds = collect($design->document['sides'])->map(fn ($side) => $side['template_version_id'])->filter()->all();
                abort_unless(($design->options['ready_locked'] ?? false) && $design->options == $configuration['options'] && $templateIds == array_map(fn ($template) => $template['id'], $configuration['templates']), 409, 'أعد فتح خيارات الطلب لإضافة اختيار مختلف.');
            }
            $existingItem = $design ? CartItem::where('guest_session_id', session('guest_id'))->where('design_version_id', $design->versions()->value('id'))->first() : null;
            abort_if($previous && $existingItem && $previous->id !== $existingItem->id, 409, 'هذه الخيارات موجودة في بند آخر بالسلة. أعد فتح خيارات الطلب.');
            if (! $previous && ! $existingItem && CartItem::where('guest_session_id', session('guest_id'))->count() >= 50) {
                throw ValidationException::withMessages(['cart' => 'الحد الأعلى 50 تصميماً في السلة.']);
            }
            if (! $design) {
                $asset = $product->catalogAsset ? $this->catalog->guestAsset($product->catalogAsset) : null;
                $document = $this->document($product, $configuration, $asset?->id);
                $design = Design::create(['id' => $request['request_id'], 'guest_session_id' => session('guest_id'), 'user_id' => auth()->id(), 'name' => $product->name, 'document' => $document, 'options' => $configuration['options'], 'revision' => 1]);
                DesignVersion::create(['design_id' => $design->id, 'revision' => 1, 'document' => $document, 'options' => $configuration['options'], 'templates' => $configuration['templates']]);
            }
            $version = $design->versions()->firstOrFail();
            $item = $previous ?? $existingItem ?? new CartItem(['id' => (string) Str::uuid(), 'guest_session_id' => session('guest_id')]);
            $item->fill(['design_version_id' => $version->id, 'product_id' => $product->id, 'quantity' => $configuration['quantity']])->save();

            return $item;
        });
    }
}
