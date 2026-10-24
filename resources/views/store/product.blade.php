<x-layout :title="$product->name" :private="$cartItem!==null">
@php
    $basePrice = $product->base_price_iqd;
    $hasDistinctDescription = filled($product->description) && mb_strtolower(trim($product->description)) !== mb_strtolower(trim($product->name));
    $structuredData = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $hasDistinctDescription ? $product->description : null,
        'image' => $product->image_url,
        'brand' => ['@type' => 'Brand', 'name' => 'STICKER FIGHT'],
        'offers' => $basePrice !== null ? ['@type' => 'Offer', 'price' => $basePrice, 'priceCurrency' => 'IQD', 'url' => url('/products/'.$product->slug)] : null,
    ]);
@endphp
@push('head')<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>@endpush
<div class="container page-space">
    <div class="breadcrumbs"><a href="/">{{ __('الرئيسية') }}</a><span>/</span><a href="/designs">{{ __('التصاميم') }}</a><span>/</span>{{ $product->name }}</div>
    <div class="product-detail">
        <div class="detail-art"><span class="eyebrow">STICKER FIGHT / CARD SKINS</span>
            <div id="ready-original-preview" class="card-mock"><img src="{{ $product->image_url }}" alt="{{ $product->name }}" draggable="false"><span class="mock-chip"><i></i></span></div>
            <div id="ready-preview" class="ready-preview" hidden></div>
            <small>{{ __('معاينة للتصميم الثابت. الفتحات تتبع قالب بطاقتك الذي تختاره.') }}</small>
        </div>
        <div class="detail-copy"><span class="eyebrow">CUSTOM CARD SKINS</span><h1>{{ $product->name }}</h1>@if($product->is_featured)<span class="featured-badge"><x-icon name="sparkles" size="13"/>{{ __('تصميم مميز') }}</span>@endif @if($product->is_bestseller)<span class="bestseller-badge"><x-icon name="sparkles" size="13"/>{{ __('اكثر مبيعاً') }}</span>@endif @if($hasDistinctDescription)<p dir="ltr" class="latin">{{ $product->description }}</p>@endif
            <p>{{ __('ستيكر جاهز لبطاقتك الموجودة عندك. الرسم ثابت؛ اختار خيارات الطلب وضيفه للسلة.') }}</p>
            @if($basePrice!==null)<div class="detail-price">{{ number_format($basePrice) }} <small>{{ __('د.ع — قبل الإضافات والتوصيل') }}</small></div>@else<p class="notice">{{ __('سعر هذا المنتج غير محدد حالياً.') }}</p>@endif
            <ul class="product-highlights"><li><x-icon name="card" size="18"/>{{ __('ستيكر لبطاقتك الموجودة') }}</li><li><x-icon name="shield" size="18"/>{{ __('لا نطلب بيانات بطاقتك المصرفية.') }}</li>@if(\App\Services\StoreSettings::get('cod_enabled',true))<li><x-icon name="bag" size="18"/>{{ __('الدفع عند الاستلام') }}</li>@endif</ul>
            @include('store.ready-options')
            <details><summary>{{ __('شنو يوصلني؟') }}</summary><p>{{ __('ملصق أو ملصقان منفصلان حسب اختيارك. الوجه الشفاف خامة غير مطبوعة؛ الفتحات والمناطق المستثناة تبقى مطبقة.') }}</p></details>
        </div>
    </div>
</div>
</x-layout>
