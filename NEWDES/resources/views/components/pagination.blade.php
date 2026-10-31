@if($paginator->hasPages())
@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $pages = collect([1, $last, $current - 1, $current, $current + 1])->filter(fn ($page) => $page >= 1 && $page <= $last)->unique()->sort()->values();
@endphp
<nav class="pagination" aria-label="{{ __('الصفحات') }}">@if($paginator->onFirstPage())<span class="pagination-disabled" aria-disabled="true"><x-icon name="chevron" size="16"/><span class="pagination-label">{{ __('السابق') }}</span></span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="chevron" size="16"/><span class="pagination-label">{{ __('السابق') }}</span></a>@endif<div class="pagination-pages">@foreach($pages as $index => $page)@if($index > 0 && $page - $pages[$index - 1] > 1)<span class="pagination-gap" aria-hidden="true">…</span>@endif @if($page === $current)<span class="pagination-current" aria-current="page">{{ $page }}</span>@else<a href="{{ $paginator->url($page) }}">{{ $page }}</a>@endif @endforeach</div>@if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next"><span class="pagination-label">{{ __('التالي') }}</span><x-icon name="chevron-left" size="16"/></a>@else<span class="pagination-disabled" aria-disabled="true"><span class="pagination-label">{{ __('التالي') }}</span><x-icon name="chevron-left" size="16"/></span>@endif</nav>
@endif
