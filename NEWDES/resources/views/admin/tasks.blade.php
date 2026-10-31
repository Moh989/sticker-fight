<x-admin-layout :title="__('مهام الفريق')">
    <div class="admin-heading"><div><span class="eyebrow">STICKER FIGHT / TEAM</span><h1>{{ __('مهام الفريق') }}</h1><p>{{ __('كل طلب ينتقل للمسؤول عن مرحلته حسب حالة التنفيذ.') }}</p></div><a class="button secondary small" href="{{ route('admin.orders.index') }}">{{ __('سجل الطلبات') }} <x-icon name="arrow-left" size="16"/></a></div>
    <x-team-steps :active="auth()->user()->role"/>
    <div @class(['team-task-board','single-lane'=>count($lanes)===1])>
        @foreach($lanes as $key=>$lane)
            <section class="panel team-task-lane" data-task-lane="{{ $key }}">
                <header><div><span class="eyebrow">{{ $lane['owner'] }}</span><h2>{{ __($lane['title']) }}</h2></div><b class="team-task-count">{{ number_format($lane['count']) }}</b></header>
                <p class="tool-hint">{{ __($lane['description']) }}</p>
                <div class="team-task-list">
                    @forelse($lane['orders'] as $order)
                        <article class="team-task-card" data-task-order="{{ $order->id }}">
                            <div class="team-task-card-top"><a href="{{ route('admin.orders.show',$order) }}" dir="ltr">{{ $order->number }}</a><span class="badge">{{ __(config('sticker.statuses.'.$order->status)) }}</span></div>
                            <p>{{ $order->created_at->timezone('Asia/Baghdad')->format('Y/m/d H:i') }} · {{ __(':count بند', ['count'=>$order->items_count]) }}</p>
                            @if($key==='production')
                                @if($order->items->contains('export_status','failed'))<p class="notice error">{{ __('تصدير متعثر؛ افتح الطلب لإعادة المحاولة.') }}</p>@elseif($order->items->contains(fn($item)=>$item->export_status!=='ready'))<p class="tool-hint">{{ __('ملفات الإنتاج قيد التجهيز أو تحتاج مراجعة.') }}</p>@else<span class="badge good">{{ __('الملفات جاهزة') }}</span>@endif
                            @endif
                            @if($key==='shipping'&&$order->status==='ready_to_ship')
                                @if($order->shipping_received_at)<span class="badge good">{{ __('تم استلامه لدى مسؤول التوصيل') }}</span>@else<form method="post" action="{{ route('admin.tasks.shipping.receive',$order) }}">@csrf<button class="button secondary small wide">{{ __('استلمت الطلب الجاهز') }}</button></form>@endif
                            @endif
                            <a class="text-link" href="{{ route('admin.orders.show',$order) }}">{{ __(['orders'=>'مراجعة وتأكيد الطلب','production'=>'فتح التصميم وملفات الإنتاج','shipping'=>'فتح بيانات التوصيل'][$key]) }} <x-icon name="arrow-left" size="14"/></a>
                        </article>
                    @empty<div class="empty-state"><x-icon name="check" size="28"/><p>{{ __('لا توجد مهام بانتظار هذه المرحلة.') }}</p></div>@endforelse
                </div>
                <a class="button secondary small wide" href="{{ route('admin.orders.index',['stage'=>$key]) }}">{{ __('عرض كل مهام المرحلة') }}</a>
            </section>
        @endforeach
    </div>
</x-admin-layout>
