@props(['variant'=>'controller','eager'=>false])
@php
    $label=\App\Models\ProductSection::ILLUSTRATIONS[$variant]??null;
    $cover=$label?'art/section-'.$variant.'-cover-':null;
@endphp
@if($cover)<img class="section-cover-image" src="{{ asset('art/newdes-'.$variant.'.svg') }}" width="600" height="480" loading="{{ $eager?'eager':'lazy' }}" decoding="async" alt="{{ __('غلاف قسم :name',['name'=>__($label)]) }}">@else<x-section-art :variant="$variant"/>@endif
