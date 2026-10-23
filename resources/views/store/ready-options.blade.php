@php
    $selected = $cartItem?->version->options ?? ['mode'=>'single','printed_side'=>'front','transparent_side'=>'front'];
    $printingMaterials = $materials->where('transparent',false);
    $clearMaterials = $materials->where('transparent',true);
    $chosenMaterial = $selected['material_id'] ?? $printingMaterials->first()?->id;
    $chosenClear = $selected['transparent_material_id'] ?? $clearMaterials->first()?->id;
@endphp
<form id="ready-product-form" action="/api/products/{{ $product->id }}/cart" method="post"
      data-editing-cart="{{ $cartItem?'1':'0' }}" data-quote-url="/api/products/{{ $product->id }}/quote" data-image-url="{{ $product->preview_image_url }}"
      data-configured="{{ $printingMaterials->isNotEmpty()&&$templates->isNotEmpty()?'1':'0' }}">
    @csrf
    <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    @if($cartItem)<input type="hidden" name="cart_item_id" value="{{ $cartItem->id }}">@endif
    <div class="ready-order-heading" id="order-options"><x-icon name="lock" size="18"/><strong>{{ __('تصميم جاهز ثابت — بدون تعديل على الرسم') }}</strong></div>
    <p class="tool-hint">{{ __('اختار خيارات الطباعة فقط. يُستخدم نفس التصميم لكل جهة مطبوعة.') }}</p>
    @if($printingMaterials->isEmpty())<p class="notice warning">{{ __('لا توجد خامة طباعة متاحة حالياً. الطلب غير متاح لهذا التصميم.') }}</p>@endif
    @if($templates->isEmpty())<p class="notice warning">{{ __('لا توجد قوالب إنتاج معتمدة حالياً. الطلب غير متاح لهذا التصميم.') }}</p>@endif
    <div class="form-grid">
        <label>{{ __('نوع الستيكر') }}<select name="mode" required>
            @foreach(['single'=>'وجه واحد','double'=>'وجهان مطبوعان','mixed'=>'شفاف + مطبوع'] as $value=>$label)
                <option value="{{ $value }}" @selected(($selected['mode']??'single')===$value)>{{ __($label) }}</option>
            @endforeach
        </select></label>
        <label data-ready-field="printed_side">{{ __('الجهة المطبوعة') }}<select name="printed_side"><option value="front" @selected(($selected['printed_side']??'front')==='front')>{{ __('الأمام') }}</option><option value="back" @selected(($selected['printed_side']??'front')==='back')>{{ __('الخلف') }}</option></select></label>
        <label data-ready-field="transparent_side" hidden>{{ __('الجهة الشفافة') }}<select name="transparent_side"><option value="front" @selected(($selected['transparent_side']??'front')==='front')>{{ __('الأمام') }}</option><option value="back" @selected(($selected['transparent_side']??'front')==='back')>{{ __('الخلف') }}</option></select></label>
        <label>{{ __('خامة الطباعة') }}<select name="material_id" required>
            @if($printingMaterials->isEmpty())<option value="">{{ __('غير متاحة حالياً') }}</option>@endif
            @foreach($printingMaterials as $material)<option value="{{ $material->id }}" @selected((int)$chosenMaterial===$material->id)>{{ $material->name }}@if($material->surcharge_iqd) (+{{ number_format($material->surcharge_iqd) }} {{ __('د.ع لكل ملصق') }})@endif</option>@endforeach
        </select></label>
        <label data-ready-field="transparent_material_id" hidden>{{ __('الخامة الشفافة غير المطبوعة') }}<select name="transparent_material_id">
            @if($clearMaterials->isEmpty())<option value="">{{ __('غير متاحة حالياً') }}</option>@endif
            @foreach($clearMaterials as $material)<option value="{{ $material->id }}" @selected((int)$chosenClear===$material->id)>{{ $material->name }}@if($material->surcharge_iqd) (+{{ number_format($material->surcharge_iqd) }} {{ __('د.ع لكل ملصق') }})@endif</option>@endforeach
        </select></label>
        <label>{{ __('التشطيب') }}<select name="finish_id"><option value="">{{ __('بدون إضافة تشطيب') }}</option>@foreach($finishes as $finish)<option value="{{ $finish->id }}" @selected((int)($selected['finish_id']??0)===$finish->id)>{{ $finish->name }}@if($finish->surcharge_iqd) (+{{ number_format($finish->surcharge_iqd) }} {{ __('د.ع لكل ملصق مطبوع') }})@endif</option>@endforeach</select><small class="tool-hint">{{ __('التشطيب يطبق على الجهات المطبوعة فقط.') }}</small></label>
        <label>{{ __('الكمية') }}<input type="number" name="quantity" min="1" max="100" value="{{ $cartItem?->quantity??1 }}" required></label>
    </div>
    <div class="ready-template-groups">
        @foreach(['front'=>'قالب الأمام','back'=>'قالب الخلف'] as $side=>$label)
            <fieldset data-ready-template-side="{{ $side }}" @if($side==='back') hidden @endif><legend>{{ __($label) }}</legend>
                <p class="tool-hint">{{ __('اختار الرسمة المطابقة لشكل بطاقتك، بدون رفع صورة بطاقتك المصرفية.') }}</p>
                @forelse($templates->where('side',$side) as $template)
                    <label class="template-option"><input type="radio" name="{{ $side }}_template_id" value="{{ $template['id'] }}" @checked(($cartItem?->version->document['sides'][$side]['template_version_id']??null)===$template['id'])>
                        <span class="mini-template" data-ready-template="{{ json_encode($template,JSON_UNESCAPED_UNICODE) }}"></span>
                        <span><strong>{{ $template['name'] }}</strong><small>v{{ $template['version'] }} · {{ __('قالب معتمد') }}</small></span>
                    </label>
                @empty<p class="tool-hint">{{ __('لا يوجد قالب معتمد متاح لهذه الجهة حالياً.') }}</p>@endforelse
                <p class="tool-hint" data-ready-no-compatible hidden>{{ __('لا يوجد قالب متوافق مع هذه الخيارات. جرّب خامة أو تشطيباً أو نوع جهات آخر.') }}</p>
            </fieldset>
        @endforeach
    </div>
    <a href="/guide" class="text-link">{{ __('لم أجد شكل بطاقتي') }} <x-icon name="card" size="17"/></a>
    <div id="ready-summary" class="notice" role="status">{{ __('اختار القالب وخيارات الطلب لحساب الإجمالي.') }}</div>
    <div id="ready-error" role="alert"></div>
    <button type="submit" id="ready-add-to-cart" class="button primary wide" disabled>{{ $cartItem?__('تحديث خيارات الطلب'):__('ضيف للسلة') }} <x-icon name="bag" size="18"/></button>
    <p class="tool-hint">{{ __('السعر النهائي حسب الجهات والخامة والتشطيب والكمية. التوصيل يُحسب عند إتمام الطلب.') }}</p>
</form>
