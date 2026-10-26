@props(['variant'=>'controller','eager'=>false])
@php
    $label=\App\Models\ProductSection::ILLUSTRATIONS[$variant]??null;
    $cover=$label?'art/section-'.$variant.'-cover-':null;
@endphp
@if($cover)
    <img class="section-cover-image" src="{{ asset($cover.'960.webp') }}" srcset="{{ asset($cover.'480.webp') }} 480w, {{ asset($cover.'960.webp') }} 960w" sizes="(max-width: 540px) calc(100vw - 36px), (max-width: 800px) calc((100vw - 64px) / 2), (max-width: 1180px) calc((100vw - 104px) / 2), 400px" width="960" height="960" loading="{{ $eager?'eager':'lazy' }}" decoding="async" alt="{{ __('غلاف قسم :name',['name'=>__($label)]) }}" draggable="false">
@else
    <x-section-art :variant="$variant"/>
@endif
