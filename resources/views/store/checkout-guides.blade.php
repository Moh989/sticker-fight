<div class="checkout-guides" data-checkout-guides>
    <div class="checkout-guides-heading"><h3>{{ __('قبل ما تأكّد الطلب') }}</h3><button type="button" class="button secondary small" data-motion-toggle aria-pressed="true" aria-controls="checkout-guide-images" hidden>{{ __('إيقاف الحركة') }}</button></div>
    <p class="tool-hint">{{ __('شوف شرح الملصق وطريقة لصقه على البطاقة.') }}</p>
    <div id="checkout-guide-images" class="checkout-guide-images">
        <figure><img src="{{ asset('art/checkout-apply.webp') }}" width="480" height="270" loading="lazy" decoding="async" alt="{{ __('شرح متحرك لمحاذاة الملصق ولصقه تدريجياً على البطاقة.') }}"><figcaption><strong>{{ __('لصق الملصق خطوة بخطوة') }}</strong><p>{{ __('حاذِ الملصق مع البطاقة وثبّته بالتدريج. تبقى الشريحة والمناطق المستثناة مكشوفة.') }}</p></figcaption></figure>
        <figure><img src="{{ asset('art/checkout-closeup.webp') }}" width="338" height="270" loading="lazy" decoding="async" alt="{{ __('صورة متحركة توضح الملصق عن قرب وإخراجه من غلاف توضيحي.') }}"><figcaption><strong>{{ __('شوف الملصق عن قرب') }}</strong><p>{{ __('الصور توضيحية للملصق؛ الغلاف الظاهر في المثال لا يمثل تغليف طلبك.') }}</p></figcaption></figure>
    </div>
    <p class="tool-hint" data-motion-paused hidden>{{ __('الحركة موقوفة. اضغط عرض الصور المتحركة لمشاهدة الشرح.') }}</p>
    <a class="text-link" href="{{ url('/installation') }}">{{ __('شلون أركّبه؟') }} <x-icon name="arrow-left" size="16"/></a>
</div>
