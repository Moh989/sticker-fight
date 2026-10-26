@php
    $stage=\App\Services\OrderWorkflow::stage($order);
    $canDownload=\App\Services\OrderWorkflow::canDownload(auth()->user());
    $nextStatuses=\App\Services\OrderWorkflow::nextStatuses($order,auth()->user());
    if(!$order->shipping_received_at){$nextStatuses=array_values(array_diff($nextStatuses,['shipped']));}
    $isShipping=auth()->user()->role==='shipping';
@endphp
<x-admin-layout :title="__('مراجعة الطلب')">
    <div class="admin-heading"><div><span class="eyebrow">ORDER REVIEW</span><h1 dir="ltr">{{ $order->number }}</h1><p>{{ $order->created_at->timezone('Asia/Baghdad')->format('Y/m/d H:i') }} · <span class="badge pink">{{ __(config('sticker.statuses.'.$order->status)) }}</span></p></div><a href="{{ route('admin.tasks.index') }}" class="button secondary small">{{ __('مهام الفريق') }} <x-icon name="arrow-left" size="17"/></a></div>
    <x-team-steps :active="$stage"/>
    <div class="admin-two-col"><div>
        @foreach($order->items as $item)
            <article class="admin-order-item">
                <h2>{{ $item->snapshot['name'] }}</h2><p>{{ __('بند :id · :quantity قطعة · إصدار التصميم :revision',['id'=>$item->id,'quantity'=>$item->quantity,'revision'=>$item->snapshot['design_revision']]) }}</p>
                @unless($isShipping)
                    @if(isset($item->snapshot['document']))
                        <div class="order-previews">@foreach($item->snapshot['options']['mode']==='single'?[$item->snapshot['options']['printed_side']]:['front','back'] as $side)<figure><x-design-preview :document="$item->snapshot['document']" :templates="$item->snapshot['templates']" :options="$item->snapshot['options']" :side="$side" :staff="true" :item-id="$item->id" :quality-assets="collect($item->snapshot['assets'])->mapWithKeys(fn($a,$id)=>[$id=>collect($a)->only(['width','height'])->all()])->all()"/><figcaption>{{ __($side==='front'?'الأمام':'الخلف') }} · {{ __($item->snapshot['options']['mode']==='mixed'&&$item->snapshot['options']['transparent_side']===$side?'شفاف غير مطبوع':'مطبوع') }} · {{ $item->snapshot['templates'][$side]['name'] }} / v{{ $item->snapshot['templates'][$side]['version'] }}</figcaption></figure>@endforeach</div>
                    @else<div class="notice">{{ __('انتهت مدة الاحتفاظ بملفات هذا التصميم حسب إعدادات المتجر.') }}</div>@endif
                    <div class="summary-row"><span>{{ __('حالة التصدير') }}</span><span class="badge {{ $item->export_status==='ready'?'good':'warn' }}">{{ __(['pending'=>'في الانتظار','processing'=>'جارٍ التصدير','ready'=>'الملفات جاهزة','failed'=>'فشل التصدير','expired'=>'انتهت مدة الاحتفاظ'][$item->export_status]) }}</span></div>
                    @if($canDownload)
                        <div class="file-links">@foreach($item->files as $file)<a href="/admin/files/{{ $file->id }}"><x-icon name="download" size="13"/>{{ $file->side }} · {{ $file->kind==='zip'?__('حزمة الإنتاج ZIP'):$file->kind }}</a>@endforeach</div>
                        @if(in_array($item->export_status,['failed','ready']))<form action="/admin/items/{{ $item->id }}/retry" method="post" style="margin-top:15px">@csrf<button class="button secondary small">{{ __('إعادة التصدير') }}</button></form>@endif
                        <details style="margin-top:20px"><summary>{{ __('مواصفات الإنتاج الثابتة') }} <span>+</span></summary><pre class="json-display">{{ json_encode(collect($item->snapshot)->except('assets')->all(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
                    @endif
                @endunless
            </article>
        @endforeach
        @if($workflowEvents->isNotEmpty())
            <section class="panel team-history"><h2>{{ __('سجل تسليم المهام') }}</h2><ol>
                @foreach($workflowEvents as $event)
                    <li><strong>{{ $event->actor_name??__('حساب محذوف') }}</strong><p>@if($event->action==='order.status'){{ __('غيّر الحالة من :from إلى :to',['from'=>__(config('sticker.statuses.'.$event->changes['from'])),'to'=>__(config('sticker.statuses.'.$event->changes['to']))]) }}@elseif($event->action==='order.shipping_received'){{ __('استلم الطلب الجاهز للتوصيل') }}@else{{ __('طلب تنزيل ملف إنتاج') }} · {{ $event->changes['kind']??'' }}@endif</p><time dir="ltr">{{ $event->created_at->timezone('Asia/Baghdad')->format('Y/m/d H:i') }}</time></li>
                @endforeach
            </ol></section>
        @endif
    </div><aside class="form-stack">
        @if($order->status==='ready_to_ship'&&in_array(auth()->user()->role,['admin','shipping']))
            <section class="panel"><h2 class="team-panel-title">{{ __('استلام جعفر للطلب الجاهز') }}</h2>
                @if($order->shipping_received_at)<p class="notice success">{{ __('تم تسجيل الاستلام') }}</p><p>{{ $order->shippingReceiver?->name??__('حساب محذوف') }} · {{ $order->shipping_received_at->timezone('Asia/Baghdad')->format('Y/m/d H:i') }}</p>@else<p class="tool-hint">{{ __('أكد استلام الطلب المجهز قبل تسليمه للتوصيل.') }}</p><form method="post" action="{{ route('admin.tasks.shipping.receive',$order) }}">@csrf<button class="button primary wide">{{ __('استلمت الطلب الجاهز') }}</button></form>@endif
            </section>
        @endif
        <section class="panel"><h2 class="team-panel-title">{{ __('إجراء على الطلب') }}</h2>
            @if($nextStatuses)
                <form class="form-stack" action="/admin/orders/{{ $order->id }}/status" method="post">@csrf<label>{{ __('حالة التنفيذ') }}<select name="status">@foreach($nextStatuses as $key)<option value="{{ $key }}">{{ __(config('sticker.statuses.'.$key)) }}</option>@endforeach</select></label><button class="button primary">{{ __('تحديث الحالة') }}</button></form>
            @elseif($isShipping&&$order->status==='ready_to_ship'&&!$order->shipping_received_at)<p>{{ __('سجّل استلام الطلب الجاهز لدى مسؤول التوصيل أولاً.') }}</p>@else<p>{{ __('لا توجد خطوة متاحة لدورك في المرحلة الحالية.') }}</p>@endif
            <p class="tool-hint">{{ __('بدء الطباعة يتطلب اعتماد الطلب ونجاح التصدير. لا يغيّر التصدير حالة التنفيذ تلقائياً.') }}</p>
        </section>
        @if(auth()->user()->role!=='production')
            <section class="panel"><h2 class="team-panel-title">{{ __('بيانات التوصيل') }}</h2><p>{{ $order->customer->name }}</p><p dir="ltr" style="text-align:right">{{ $order->customer->phone }}</p>@if($order->customer->alternate_phone)<p dir="ltr" style="text-align:right">{{ $order->customer->alternate_phone }}</p>@endif<p>{{ collect($order->address)->only(['governorate','city','district'])->filter()->implode(' / ') }}</p>@if(!empty($order->address['address']))<p>{{ $order->address['address'] }}</p>@endif<p>{{ __('أقرب نقطة: :landmark',['landmark'=>$order->address['landmark']]) }}</p>@if($order->notes)<p>{{ $order->notes }}</p>@endif</section>
            <section class="panel"><h2 class="team-panel-title">{{ __('التحصيل المالي') }}</h2><div class="summary-row"><span>{{ __('إجمالي الطلب') }}</span><b>{{ number_format($order->totals['total_iqd']) }} {{ __('د.ع') }}</b></div><div class="summary-row"><span>{{ __('تم تحصيله') }}</span><b>{{ number_format(\App\Models\Collection::netFor($order->id)) }} {{ __('د.ع') }}</b></div>
                @if(in_array(auth()->user()->role,['admin','orders']))
                    <form action="/admin/orders/{{ $order->id }}/payment" method="post" class="form-stack">@csrf<label>{{ __('مبلغ محصل / د.ع') }}<input name="amount_iqd" type="number" required min="1"></label><label>{{ __('مرجع التحصيل') }}<input name="reference" required maxlength="150"></label><button class="button secondary">{{ __('تسجيل تحصيل فعلي') }}</button></form>
                    @if(in_array($order->status,['cancelled','returned'])&&\App\Models\Collection::netFor($order->id)>0)<details style="margin-top:20px"><summary>{{ __('تسجيل إعادة مبلغ') }}</summary><p>{{ __('سجل المبلغ بعد إعادته فعلياً للزبون. هذا الإجراء لا يحوّل أموالاً إلكترونياً.') }}</p><form method="post" action="/admin/orders/{{ $order->id }}/refund" class="form-stack">@csrf<label>{{ __('المبلغ المعاد / د.ع') }}<input name="amount_iqd" type="number" min="1" required></label><label>{{ __('مرجع إعادة المبلغ') }}<input name="reference" maxlength="150" required></label><button class="button secondary">{{ __('تسجيل المبلغ المعاد') }}</button></form></details>@endif
                @endif
            </section>
        @endif
    </aside></div>
</x-admin-layout>
