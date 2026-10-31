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
        'offers' => !config('sticker.demo_mode') && $basePrice !== null ? ['@type' => 'Offer', 'price' => $basePrice, 'priceCurrency' => 'IQD', 'url' => url('/products/'.$product->slug)] : null,
    ]);
@endphp
@push('head')<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>@endpush
<div class="container page-space">
    <div class="breadcrumbs"><a href="/">{{ __('الرئيسية') }}</a><span>/</span><a href="/designs">{{ __('التصاميم') }}</a><span>/</span>{{ __($product->name) }}</div>
    <div class="product-detail">
        <div class="detail-art">
            <div id="ready-original-preview" class="card-mock"><img src="{{ $product->image_url }}" alt="{{ __($product->name) }}" draggable="false"></div>
            <div id="ready-preview" class="ready-preview" hidden></div>
            <small>{{ __('معاينة للتصميم الثابت. الفتحات تتبع قالب بطاقتك الذي تختاره.') }}</small>
        </div>
        <div class="detail-copy"><h1>{{ __($product->name) }}</h1>@if($product->is_featured)<span class="featured-badge"><x-icon name="sparkles" size="13"/>{{ __('تصميم مميز') }}</span>@endif @if($product->is_bestseller)<span class="bestseller-badge"><x-icon name="sparkles" size="13"/>{{ __('الأكثر مبيعاً') }}</span>@endif @if($hasDistinctDescription)<p dir="auto">{{ __($product->description) }}</p>@endif
            <p>@if($product->personalization_enabled){{ __('تصميم جاهز تضيف عليه اسمك وتختار خطك. بقية الرسم ثابتة.') }}@else{{ __('ستيكر جاهز لبطاقتك الموجودة عندك. الرسم ثابت؛ اختار خيارات الطلب وضيفه للسلة.') }}@endif</p>
            @if($basePrice!==null)<div class="detail-price">{{ number_format($basePrice) }} <small>{{ __('د.ع — قبل الإضافات والتوصيل') }}</small></div>@else<p class="notice">{{ __('سعر هذا المنتج غير محدد حالياً.') }}</p>@endif
            <ul class="product-highlights"><li><x-icon name="card" size="18"/>{{ __('ستيكر لبطاقتك الموجودة') }}</li><li><x-icon name="shield" size="18"/>{{ __('لا نطلب بيانات بطاقتك المصرفية.') }}</li>@if(\App\Services\StoreSettings::get('cod_enabled',true))<li><x-icon name="bag" size="18"/>{{ __('الدفع عند الاستلام') }}</li>@endif</ul>
            @include('store.ready-options')
            <div class="product-help-links"><a class="text-link" href="{{ route('guide') }}">{{ __('دليل القوالب') }}</a> · <a class="text-link" href="{{ route('installation') }}">{{ __('طريقة التركيب') }}</a></div>
            @foreach(\App\Models\ContentPage::whereIn('slug',['shipping','returns'])->where('published',true)->get() as $policy)<details><summary>{{ __($policy->title) }}</summary><p>{{ $policy->body }}</p></details>@endforeach
            <details><summary>{{ __('شنو يوصلني؟') }}</summary><p>{{ __('ملصق أو ملصقان منفصلان حسب اختيارك. الوجه الشفاف خامة غير مطبوعة؛ الفتحات والمناطق المستثناة تبقى مطبقة.') }}</p></details>
        </div>
    </div>
@if($related->isNotEmpty())<section class="related-section"><div class="section-heading"><h2>{{ __('يمكن تعجبك أيضاً') }}</h2></div><div class="product-grid">@foreach($related as $relatedProduct)<x-product-card :product="$relatedProduct"/>@endforeach</div></section>@endif
</div>
</x-layout>
