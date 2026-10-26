<?php

namespace App\Services;

use App\Models;

class AdminResources
{
    public static function all(): array
    {
        return [
            'sections' => ['label' => 'أقسام الأجهزة والستيكرات', 'model' => Models\ProductSection::class, 'fields' => ['name' => ['اسم القسم', 'text', 'required|string|max:100'], 'slug' => ['الرابط', 'text', 'required|alpha_dash|max:100'], 'description' => ['وصف القسم', 'textarea', 'nullable|string|max:2000'], 'illustration' => ['الرسم التوضيحي', 'section_art', 'required|in:'.implode(',', array_keys(Models\ProductSection::ILLUSTRATIONS))], 'model_hint' => ['مثال لاسم الموديل', 'text', 'nullable|string|max:150'], 'requires_model' => ['اسم الموديل مطلوب', 'checkbox', 'boolean'], 'sort_order' => ['ترتيب العرض', 'number', 'required|integer|between:0,10000'], 'active' => ['ظاهر في المتجر', 'checkbox', 'boolean']]],
            'products' => ['label' => 'التصاميم والمنتجات', 'model' => Models\Product::class, 'fields' => ['name' => ['الاسم', 'text', 'required|string|max:100'], 'slug' => ['الرابط', 'text', 'required|alpha_dash|max:100'], 'product_section_id' => ['قسم المنتج (فارغ: ستيكرات البطاقات)', 'product_section', 'nullable|integer|exists:product_sections,id'], 'category_id' => ['التصنيف', 'category', 'nullable|integer|exists:categories,id'], 'description' => ['الوصف', 'textarea', 'nullable|string|max:2000'], 'art_key' => ['الرسم الأصلي', 'art', 'nullable|in:'.implode(',', DesignValidator::ARTS)], 'price_iqd' => ['سعر المنتج / د.ع (اختياري)', 'number', 'nullable|integer|min:0|max:100000000'], 'is_featured' => ['تصميم مميز', 'checkbox', 'boolean'], 'active' => ['ظاهر في المتجر', 'checkbox', 'boolean']]],
            'categories' => ['label' => 'التصنيفات', 'model' => Models\Category::class, 'fields' => ['name' => ['الاسم', 'text', 'required|string|max:100'], 'slug' => ['الرابط', 'text', 'required|alpha_dash|max:100'], 'description' => ['الوصف', 'textarea', 'nullable|string|max:1000'], 'active' => ['نشط', 'checkbox', 'boolean']]],
            'materials' => ['label' => 'الخامات', 'model' => Models\Material::class, 'fields' => ['name' => ['الاسم', 'text', 'required|string|max:100'], 'code' => ['رمز الخامة', 'text', 'required|alpha_dash|max:100'], 'notes' => ['المواصفة المعتمدة', 'textarea', 'nullable|string|max:2000'], 'surcharge_iqd' => ['الإضافة لكل ملصق / د.ع', 'number', 'required|integer|min:0|max:100000000'], 'transparent' => ['نوع الخامة', 'material_kind', 'required|boolean'], 'active' => ['متاح فعلياً', 'checkbox', 'boolean']]],
            'finishes' => ['label' => 'التشطيبات', 'model' => Models\Finish::class, 'fields' => ['name' => ['الاسم', 'text', 'required|string|max:100'], 'surcharge_iqd' => ['الإضافة / د.ع', 'number', 'required|integer|min:0|max:100000000'], 'active' => ['متاح فعلياً', 'checkbox', 'boolean']]],
            'governorates' => ['label' => 'المحافظات', 'model' => Models\Governorate::class, 'fields' => ['name' => ['الاسم', 'text', 'required|string|max:100'], 'active' => ['نشطة', 'checkbox', 'boolean']]],
            'areas' => ['label' => 'المناطق', 'model' => Models\Area::class, 'fields' => ['name' => ['اسم المنطقة', 'text', 'required|string|max:100'], 'governorate_id' => ['المحافظة', 'governorate', 'required|integer|exists:governorates,id'], 'active' => ['نشطة', 'checkbox', 'boolean']]],
            'shipping' => ['label' => 'التوصيل والتغطية', 'model' => Models\ShippingRate::class, 'fields' => ['governorate_id' => ['المحافظة', 'governorate', 'required|integer|exists:governorates,id'], 'area_id' => ['المنطقة (فارغة: كامل المحافظة)', 'area', 'nullable|integer|exists:areas,id'], 'fee_iqd' => ['أجرة التوصيل / د.ع', 'number', 'nullable|integer|min:0|max:1000000'], 'estimated_time' => ['المدة التقديرية المعتمدة', 'text', 'nullable|string|max:100'], 'active' => ['التوصيل متاح', 'checkbox', 'boolean']]],
            'bundles' => ['label' => 'الباقات', 'model' => Models\Bundle::class, 'fields' => ['name' => ['اسم الباقة', 'text', 'required|string|max:100'], 'minimum_quantity' => ['عدد القطع الأدنى', 'number', 'required|integer|min:2|max:100'], 'discount_percent' => ['نسبة خصم المنتجات', 'number', 'required|integer|min:0|max:100'], 'active' => ['نشطة', 'checkbox', 'boolean']]],
            'coupons' => ['label' => 'الكوبونات والعروض', 'model' => Models\Coupon::class, 'fields' => ['code' => ['رمز الخصم', 'text', 'required|alpha_dash|max:40'], 'type' => ['النوع', 'coupon_type', 'required|in:fixed,percent'], 'value' => ['القيمة أو النسبة', 'number', 'required|integer|min:0|max:100000000'], 'minimum_iqd' => ['الحد الأدنى للمنتجات / د.ع', 'number', 'required|integer|min:0|max:100000000'], 'usage_limit' => ['الحد الأعلى للاستخدام', 'number', 'nullable|integer|min:1'], 'starts_at' => ['بداية العرض', 'datetime-local', 'nullable|date'], 'ends_at' => ['نهاية العرض', 'datetime-local', 'nullable|date|after_or_equal:starts_at'], 'active' => ['نشط', 'checkbox', 'boolean']]],
            'pages' => ['label' => 'الصفحات والسياسات', 'model' => Models\ContentPage::class, 'fields' => ['title' => ['عنوان الصفحة', 'text', 'required|string|max:200'], 'slug' => ['الرابط', 'text', 'required|alpha_dash|max:100'], 'body' => ['المحتوى (نص آمن)', 'textarea', 'nullable|string|max:30000'], 'published' => ['نشر الصفحة', 'checkbox', 'boolean']]],
        ];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? abort(404);
    }
}
