@props(['theme'=>'light'])
<button type="button" class="icon-button theme-toggle" data-theme-toggle aria-label="{{ __('النمط النهاري') }}" aria-pressed="{{ $theme==='light'?'true':'false' }}" title="{{ $theme==='light'?__('التحويل إلى النمط الليلي'):__('التحويل إلى النمط النهاري') }}">
    <span class="theme-icon-night"><x-icon name="moon" size="19"/></span><span class="theme-icon-day"><x-icon name="sun" size="19"/></span>
</button>
