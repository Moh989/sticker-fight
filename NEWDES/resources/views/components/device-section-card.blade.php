@props(['section','index'=>1])
<a href="{{ route('devices.section',$section->slug) }}" class="device-section-card" data-device-section="{{ $section->slug }}">
    <div class="device-section-art device-section-photo"><x-section-cover :variant="$section->illustration"/></div>
    <div class="device-section-copy"><h3>{{ __($section->name) }}</h3><p>{{ __($section->description) }}</p><span class="device-section-link">{{ __('شوف القسم') }} <x-icon name="arrow-left" size="18"/></span></div>
</a>
