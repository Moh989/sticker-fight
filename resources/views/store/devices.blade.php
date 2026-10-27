<x-layout :title="__('الأجهزة')" :description="__('ستيكرات لأجهزة التحكم والكونسل واللابتوبات، وملصقات للمشاريع. اختار القسم وخلّينا نراجع فكرتك.')">
    <div class="container page-space devices-page">
        <div class="breadcrumbs"><a href="{{ route('home') }}">{{ __('الرئيسية') }}</a><span>/</span>{{ __('الأجهزة') }}</div>
        <section class="devices-hero">
            <div><h1>{{ __('مو بس بطاقتك.') }} <span class="pink">{{ __('أجهزتك هم تستاهل.') }}</span></h1><p>{{ __('من يد التحكم إلى جهازك ومشروعك. اختار مساحتك، وخلّي الباقي على ذوقك.') }}</p><a href="#device-sections" class="button primary">{{ __('اختار قسمك') }} <x-icon name="arrow-left" size="18"/></a><span class="devices-hero-note">{{ __('نبيع ستيكرات للأجهزة، مو الأجهزة نفسها.') }}</span></div>
            <div class="devices-hero-art" aria-hidden="true"><x-section-art variant="controller"/></div>
        </section>
        <section class="device-sections" id="device-sections" aria-labelledby="device-sections-title"><div class="section-heading"><h2 id="device-sections-title">{{ __('لكل مساحة،') }} <span class="pink">{{ __('ستيكر.') }}</span></h2><p class="muted">{{ __('الموديل والمقاس أولاً. بعدها نثبت التفاصيل.') }}</p></div><div class="device-section-grid">@forelse($sections as $section)<x-device-section-card :section="$section" :index="$loop->iteration"/>@empty<div class="empty-state"><p>{{ __('الأقسام قيد التجهيز. تواصل ويانا لمراجعة فكرتك.') }}</p><a href="/contact" class="button secondary">{{ __('التواصل') }}</a></div>@endforelse</div></section>
        <section class="devices-process"><div><h2>{{ __('فكرتك واضحة.') }} {{ __('والتفاصيل نرتّبها سوا.') }}</h2></div><ol><li><b>1</b><div><h3>{{ __('اختار القسم') }}</h3><p>{{ __('يد تحكم، كونسل، أو ملصق لمشروعك.') }}</p></div></li><li><b>2</b><div><h3>{{ __('احچيلنا التفاصيل') }}</h3><p>{{ __('الموديل، المقاس، الكمية والفكرة اللي ببالك.') }}</p></div></li><li><b>3</b><div><h3>{{ __('نراجع ونأكد') }}</h3><p>{{ __('السعر والقالب والخامة يتأكدون قبل بدء التنفيذ.') }}</p></div></li></ol></section>
    </div>
</x-layout>
