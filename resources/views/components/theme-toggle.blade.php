@props(['theme'=>'dark'])
<button type="button" class="theme-toggle" data-theme-toggle aria-label="{{ __('النمط النهاري') }}" aria-pressed="{{ $theme==='light'?'true':'false' }}" title="{{ $theme==='light'?__('التحويل إلى النمط الليلي'):__('التحويل إلى النمط النهاري') }}">
    <span class="theme-icon-night"><x-icon name="moon" size="17"/></span><span class="theme-icon-day"><x-icon name="sun" size="17"/></span>
    <span class="theme-label-night">{{ __('ليلي') }}</span><span aria-hidden="true" class="theme-separator">/</span><span class="theme-label-day">{{ __('نهاري') }}</span>
</button>
