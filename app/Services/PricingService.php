<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Coupon;
use App\Models\Finish;
use App\Models\Material;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use Illuminate\Validation\ValidationException;

class PricingService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['availability' => $message]);
    }

    public function basePrice(string $mode, ?Product $product = null): ?int
    {
        if ($product?->price_iqd === null) {
            $price = StoreSettings::get('price_'.$mode.'_iqd');

            return $price === null ? null : (int) $price;
        }

        if ($mode === 'single') {
            return $product->price_iqd;
        }

        $extra = StoreSettings::get('extra_'.$mode.'_iqd');

        return $extra === null ? null : $product->price_iqd + (int) $extra;
    }

    public function quote(array $options, array $templates, int $quantity): array
    {
        if ($quantity < 1 || $quantity > 100) {
            $this->fail('الكمية يجب أن تكون بين 1 و100.');
        }
        $mode = $options['mode'];
        $product = null;
        if (isset($options['product_id'])) {
            $product = Product::find($options['product_id']);
            if (! $product?->active) {
                $this->fail('هذا التصميم غير متاح حالياً.');
            }
        }
        $base = $this->basePrice($mode, $product);
        if ($base === null) {
            if ($product?->price_iqd !== null && $mode !== 'single') {
                $this->fail('سعر الجهات الإضافية يحتاج إعداداً.');
            }
            $this->fail('السعر يحتاج إعداداً من المتجر قبل استقبال الطلبات.');
        }
        $material = Material::find($options['material_id'] ?? null);
        if (! $material?->active || $material->transparent) {
            $this->fail('اختر خامة طباعة متاحة.');
        }
        $clear = null;
        if ($mode === 'mixed') {
            $clear = Material::find($options['transparent_material_id'] ?? null);
            if (! $clear?->active || ! $clear->transparent) {
                $this->fail('الخامة الشفافة غير متاحة.');
            }
        }
        $finish = null;
        if (! empty($options['finish_id'])) {
            $finish = Finish::find($options['finish_id']);
            if (! $finish?->active) {
                $this->fail('التشطيب غير متاح.');
            }
        }
        $sides = $mode === 'single' ? [$options['printed_side']] : ['front', 'back'];
        foreach ($sides as $side) {
            $snapshot = $templates[$side] ?? null;
            if (! $snapshot) {
                $this->fail('اختر قالباً لكل جهة مطلوبة.');
            }$v = TemplateVersion::with('template')->find($snapshot['id']);
            if (! $v || ! $v->approved_at || ! $v->template->active || ! $v->template->isStorefrontChoice()) {
                $this->fail('القالب تجريبي أو غير متاح للإنتاج.');
            }$c = $v->compatibility;
            $transparent = $mode === 'mixed' && $side === $options['transparent_side'];
            $m = $transparent ? $clear : $material;
            $sideName = $side === 'front' ? __('الأمام') : __('الخلف');
            if (! in_array($mode, $c['modes'] ?? [])) {
                $this->fail(__('نوع الستيكر المختار غير متوافق مع قالب :side. اختار قالباً مناسباً لنوع الطلب.', ['side' => $sideName]));
            }
            if (! in_array($m->id, $c['material_ids'] ?? [])) {
                $this->fail(__('الخامة «:name» غير معتمدة مع قالب :side. اختار خامة متوافقة من خيارات الطلب.', ['name' => $m->name, 'side' => $sideName]));
            }
            if (! $transparent && $finish && ! in_array($finish->id, $c['finish_ids'] ?? [])) {
                $this->fail(__('التشطيب «:name» غير متوافق مع قالب :side. اختار تشطيباً آخر أو بدون إضافة تشطيب.', ['name' => $finish->name, 'side' => $sideName]));
            }
            if ($v->template->side !== $side) {
                $this->fail('جهة القالب غير مطابقة.');
            }
        }
        $printed = $mode === 'double' ? 2 : 1;
        $unit = (int) $base + (int) $material->surcharge_iqd * $printed + (int) ($clear?->surcharge_iqd ?? 0) + (int) ($finish?->surcharge_iqd ?? 0) * $printed;

        return ['unit_iqd' => $unit, 'quantity' => $quantity, 'subtotal_iqd' => $unit * $quantity, 'currency' => 'IQD'];
    }

    public function shipping(int $governorateId, ?int $areaId = null): ShippingRate
    {
        if ($areaId && ! Area::whereKey($areaId)->where('governorate_id', $governorateId)->where('active', true)->exists()) {
            $this->fail('المنطقة لا تتبع المحافظة المختارة أو غير متاحة.');
        }$query = ShippingRate::where('governorate_id', $governorateId)->where('active', true)->whereNotNull('fee_iqd');
        $rate = $areaId ? (clone $query)->where('area_id', $areaId)->first() : null;
        $rate ??= $query->whereNull('area_id')->first();
        if (! $rate || ! $rate->governorate?->active) {
            $this->fail('التوصيل لهذه المنطقة لم يُفعّل بعد.');
        }

        return $rate;
    }

    public function discount(?string $code, int $subtotal, bool $lock = false): array
    {
        if (! $code) {
            return ['amount_iqd' => 0, 'coupon' => null];
        }$q = Coupon::where('code', mb_strtoupper(trim($code)));
        if ($lock) {
            $q->lockForUpdate();
        }$c = $q->first();
        if (! $c || ! $c->active || ($c->starts_at && $c->starts_at->isFuture()) || ($c->ends_at && $c->ends_at->isPast()) || ($c->usage_limit !== null && $c->used >= $c->usage_limit) || $subtotal < $c->minimum_iqd) {
            $this->fail('رمز الخصم غير متاح لهذا الطلب.');
        }
        $amount = $c->type === 'percent' ? intdiv($subtotal * min(100, $c->value), 100) : $c->value;

        return ['amount_iqd' => min($subtotal, $amount), 'coupon' => $c];
    }
}
