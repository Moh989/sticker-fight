<x-layout title="إتمام الطلب" :private="true">
<div class="container page-space checkout-page">
    <div class="breadcrumbs"><a href="/cart">{{ __('السلة') }}</a><span>/</span>{{ __('إتمام الطلب') }}</div>
    <div class="page-heading checkout-heading">
        <div><h1>{{ __('كمّل طلبك') }}</h1><p>{{ __('اطلب كضيف. إنشاء الحساب اختياري.') }}</p></div>
        <ol class="checkout-progress">
            <li class="is-done"><a href="/cart"><b><x-icon name="check" size="14"/></b>{{ __('السلة') }}</a></li>
            <li class="is-current" aria-current="step"><b>2</b>{{ __('بيانات الطلب') }}</li>
            <li><b>3</b>{{ __('التأكيد') }}</li>
        </ol>
    </div>
    <form id="checkout-form">
        <input type="hidden" name="request_id" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
        <div class="checkout-layout">
            <div class="checkout-main">
                <section class="form-section">
                    <h2><span>01</span> {{ __('بيانات التواصل') }}</h2>
                    <div class="form-grid">
                        <label class="span-2">{{ __('الاسم (إجباري)') }}<input name="name" autocomplete="name" required minlength="2" maxlength="100"></label>
                        <label>{{ __('رقم الهاتف (إجباري)') }}<input name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="07XXXXXXXXX" dir="ltr" required maxlength="30"></label>
                        <label>{{ __('رقم بديل (اختياري)') }}<input name="alternate_phone" type="tel" inputmode="tel" dir="ltr" maxlength="30"></label>
                        <label class="span-2">{{ __('البريد الإلكتروني (اختياري)') }}<input name="email" type="email" autocomplete="email" dir="ltr" maxlength="200"></label>
                    </div>
                </section>
                <section class="form-section">
                    <h2><span>02</span> {{ __('عنوان التوصيل') }}</h2>
                    <div class="form-grid">
                        <label>{{ __('المحافظة (إجباري)') }}<input type="text" name="governorate" id="checkout-governorate" list="checkout-governorates" autocomplete="address-level1" required maxlength="100"><datalist id="checkout-governorates">@foreach($governorates as $g)<option value="{{ __($g->name) }}"></option>@endforeach</datalist></label>
                        <label>{{ __('المنطقة (إجباري)') }}<input type="text" name="district" id="checkout-district" required maxlength="100" autocomplete="address-level2"></label>
                        <label class="span-2">{{ __('العنوان (إجباري)') }}<input type="text" name="address" autocomplete="street-address" required maxlength="500"></label>
                        <label class="span-2">{{ __('ملاحظات (اختياري)') }}<textarea name="notes" maxlength="1000" rows="2"></textarea></label>
                    </div>
                </section>
                <section class="form-section">
                    <h2><span>03</span> {{ __('الدفع والمراجعة') }}</h2>
                    @if(\App\Services\StoreSettings::get('cod_enabled',true))
                        <div class="payment-option"><span class="payment-option-icon"><x-icon name="truck" size="20"/></span><div><strong>{{ __('الدفع عند الاستلام') }}</strong><small>{{ __('بعد مراجعة الطلب وتأكيده إدارياً.') }}</small></div><span class="payment-option-check" aria-hidden="true"><x-icon name="check" size="14"/></span></div>
                    @else
                        <div class="notice warning">{{ __('وسيلة الدفع غير مفعّلة حالياً.') }}</div>
                    @endif
                    @include('store.checkout-guides')
                    @include('store.checkout-publication')
                    <div class="checkout-confirmations">
                        <label class="checkbox-label"><input type="checkbox" name="rights" value="1" required><span>{{ __('أقر بحقي في استخدام الصور والنصوص في هذا الطلب.') }}</span></label>
                        <label class="checkbox-label"><input type="checkbox" name="confirmed" value="1" required><span>{{ __('راجعت التصميم والجهات والقالب والعنوان. أفهم أن بدء الطباعة يحتاج تأكيد المتجر.') }}</span></label>
                    </div>
                </section>
            </div>
            <aside class="panel checkout-aside">
                <h2 class="summary-title">{{ __('ملخص طلبك') }}</h2>
                <ul class="checkout-items">
                    @foreach($items as $item)
                        @php($options=$item->version->options)
                        <li>
                            <x-design-preview :document="$item->version->document" :templates="$item->version->templates" :options="$options" :side="$options['mode']==='mixed'?($options['transparent_side']==='front'?'back':'front'):$options['printed_side']"/>
                            <div><strong>{{ $item->version->design->name }}</strong><small>{{ __(['single'=>'وجه واحد','double'=>'وجهان مطبوعان','mixed'=>'شفاف + مطبوع'][$options['mode']]??'') }}</small></div>
                            <span class="checkout-item-quantity" dir="ltr" aria-label="{{ __('الكمية') }}">×{{ $item->quantity }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="checkout-coupon">
                    <label for="checkout-coupon">{{ __('رمز خصم (اختياري)') }}</label>
                    <div><input name="coupon" id="checkout-coupon" maxlength="40" dir="ltr"><button type="button" id="apply-coupon" class="button secondary small">{{ __('تطبيق') }}</button></div>
                </div>
                <div id="checkout-summary"><p class="tool-hint">{{ __('اكتب المحافظة والمنطقة لعرض أجرة التوصيل والإجمالي.') }}</p></div>
                <div id="checkout-error" role="alert"></div>
                <button id="place-order" class="button primary wide" type="submit" disabled>{{ __('أكّد الطلب') }} <x-icon name="check"/></button>
                <p class="tool-hint checkout-reassurance"><x-icon name="lock" size="14"/><span>{{ __('المبالغ بالدينار العراقي. لا نطلب بيانات بطاقتك المصرفية.') }}</span></p>
                @unless(\App\Services\StoreSettings::get('accepting_orders'))<div class="notice warning">{{ __('استقبال الطلبات غير مفعّل بعد. تقدر تحفظ تصميمك وترجع لاحقاً.') }}</div>@endunless
            </aside>
        </div>
    </form>
</div>
</x-layout>
