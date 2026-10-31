@php
    $selected = $cartItem?->version->options ?? ['mode'=>'single','printed_side'=>'front','transparent_side'=>'front'];
    $printingMaterials = $materials->where('transparent',false);
    $clearMaterials = $materials->where('transparent',true);
    $chosenMaterial = $selected['material_id'] ?? $printingMaterials->first()?->id;
    $chosenClear = $selected['transparent_material_id'] ?? $clearMaterials->first()?->id;
@endphp
<form id="ready-product-form" action="/api/products/{{ $product->id }}/cart" method="post"
      data-editing-cart="{{ $cartItem?'1':'0' }}" data-quote-url="/api/products/{{ $product->id }}/quote" data-image-url="{{ $product->preview_image_url }}"
      data-configured="{{ $printingMaterials->isNotEmpty()&&$templates->contains('approved',true)?'1':'0' }}">
    @csrf
    <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    @if($cartItem)<input type="hidden" name="cart_item_id" value="{{ $cartItem->id }}">@endif
    @if($product->personalization_enabled)
        <input type="hidden" name="personalization_x" value="{{ ($selected['personalization']['positioned_by_customer']??false)?$selected['personalization']['layout']['x']:'' }}">
        <input type="hidden" name="personalization_y" value="{{ ($selected['personalization']['positioned_by_customer']??false)?$selected['personalization']['layout']['y']:'' }}">
        <section class="name-personalization" data-name-personalization data-fonts="{{ json_encode(\App\Services\DesignFonts::choices(),JSON_UNESCAPED_UNICODE) }}" data-selected-font="{{ $selected['personalization']['font']??'' }}">
            <span class="quiet-eyebrow">{{ __('التصميم جاهز… والاسم إلك') }}</span>
            <h2>{{ __('خلّي اسمك عليه') }}</h2>
            <label for="personalization-name">{{ __('الاسم اللي ينطبع') }}<input id="personalization-name" name="personalization_name" type="text" dir="auto" maxlength="30" required autocomplete="off" value="{{ $selected['personalization']['name']??'' }}" placeholder="{{ __('اكتب اسمك بالعربي أو الإنجليزي') }}" aria-describedby="name-language-hint"></label>
            <p id="name-language-hint" class="tool-hint" aria-live="polite">{{ __('الخطوط تتغيّر تلقائياً حسب لغة اسمك.') }}</p>
            <fieldset><legend>{{ __('اختار خط الاسم') }}</legend><div class="name-font-options" data-name-font-options></div></fieldset>
            <p class="tool-hint">{{ __('الاسم حتى 30 حرفاً. تختار مكانه بالسحب على معاينة البطاقة بعد اختيار القالب.') }}</p>
        </section>
    @endif
    <div class="ready-order-heading" id="order-options"><x-icon name="lock" size="18"/><strong>{{ __('تصميم جاهز ثابت — بدون تعديل على الرسم') }}</strong></div>
    <p class="tool-hint">{{ __('اختار خيارات الطباعة فقط. يُستخدم نفس التصميم لكل جهة مطبوعة.') }}</p>
    @if($printingMaterials->isEmpty())<p class="notice warning">{{ __('لا توجد خامة طباعة متاحة حالياً. الطلب غير متاح لهذا التصميم.') }}</p>@endif
    @unless($templates->contains('approved',true))<p class="notice warning">{{ __('لا توجد قوالب إنتاج معتمدة حالياً. الطلب غير متاح لهذا التصميم.') }}</p>@endunless
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
            @foreach($printingMaterials as $material)<option value="{{ $material->id }}" @selected((int)$chosenMaterial===$material->id)>{{ __($material->name) }}@if($material->surcharge_iqd) (+{{ number_format($material->surcharge_iqd) }} {{ __('د.ع لكل ملصق') }})@endif</option>@endforeach
        </select></label>
        <label data-ready-field="transparent_material_id" hidden>{{ __('الخامة الشفافة غير المطبوعة') }}<select name="transparent_material_id">
            @if($clearMaterials->isEmpty())<option value="">{{ __('غير متاحة حالياً') }}</option>@endif
            @foreach($clearMaterials as $material)<option value="{{ $material->id }}" @selected((int)$chosenClear===$material->id)>{{ __($material->name) }}@if($material->surcharge_iqd) (+{{ number_format($material->surcharge_iqd) }} {{ __('د.ع لكل ملصق') }})@endif</option>@endforeach
        </select></label>
        <label>{{ __('التشطيب') }}<select name="finish_id"><option value="">{{ __('بدون إضافة تشطيب') }}</option>@foreach($finishes as $finish)<option value="{{ $finish->id }}" @selected((int)($selected['finish_id']??0)===$finish->id)>{{ __($finish->name) }}@if($finish->surcharge_iqd) (+{{ number_format($finish->surcharge_iqd) }} {{ __('د.ع لكل ملصق مطبوع') }})@endif</option>@endforeach</select><small class="tool-hint">{{ __('التشطيب يطبق على الجهات المطبوعة فقط.') }}</small></label>
        <label>{{ __('الكمية') }}<input type="number" name="quantity" min="1" max="100" value="{{ $cartItem?->quantity??1 }}" required></label>
    </div>
    <div class="ready-template-groups">
        @foreach(['front'=>'قالب الأمام','back'=>'قالب الخلف'] as $side=>$label)
            <fieldset data-ready-template-side="{{ $side }}" @if($side==='back') hidden @endif><legend>{{ __($label) }}</legend>
                <p class="tool-hint">{{ __('اختار الرسمة المطابقة لشكل بطاقتك، بدون رفع صورة بطاقتك المصرفية.') }}</p>@if($side==='front')<p class="tool-hint">{{ __('قالب بدون شريحة للبطاقات الخالية من الشريحة فقط. لا تغطّي شريحة موجودة.') }}</p>@endif
                @forelse($templates->where('side',$side) as $template)
                    <label class="template-option"><input type="radio" name="{{ $side }}_template_id" value="{{ $template['id'] }}" @disabled(!$template['approved']) @checked(($cartItem?->version->document['sides'][$side]['template_version_id']??null)===$template['id'])>
                        <span class="mini-template" data-ready-template="{{ json_encode($template,JSON_UNESCAPED_UNICODE) }}"></span>
                        <span><strong>{{ $template['name'] }}</strong><small>v{{ $template['version'] }} · {{ $template['approved']?__('قالب معتمد'):__('بانتظار اعتماد المطبعة') }}</small></span>
                    </label>
                @empty<p class="tool-hint">{{ __('لا يوجد قالب معتمد متاح لهذه الجهة حالياً.') }}</p>@endforelse
                <p class="tool-hint" data-ready-no-compatible hidden>{{ __('لا يوجد قالب متوافق مع هذه الخيارات. جرّب خامة أو تشطيباً أو نوع جهات آخر.') }}</p>
            </fieldset>
        @endforeach
    </div>
    @if($product->personalization_enabled)
        <section id="name-position-editor" class="name-position-editor" data-name-position-editor data-default-layout="{{ json_encode($product->personalization_layout??\App\Services\ReadyNamePersonalization::DEFAULT_LAYOUT) }}" data-image-url="{{ $product->preview_image_url }}" hidden>
            <h2 id="name-position-title">{{ __('رتّب اسمك على البطاقة') }}</h2>
            <p class="tool-hint" id="name-position-instructions">{{ __('اسحب الاسم بإصبعك، أو اضغط على المكان اللي تريده.') }}</p>
            <div class="preview-skin name-position-stage" data-name-position-stage tabindex="0" role="group" aria-labelledby="name-position-title" aria-describedby="name-position-instructions name-position-status" aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight"></div>
            <div class="name-position-actions"><div class="name-position-arrows" role="group" aria-label="{{ __('تحريك الاسم خطوة بخطوة') }}"><button type="button" class="button secondary small" data-name-nudge="0,-.5" aria-label="{{ __('تحريك الاسم للأعلى') }}">↑</button><button type="button" class="button secondary small" data-name-nudge="-.5,0" aria-label="{{ __('تحريك الاسم لليسار') }}">←</button><button type="button" class="button secondary small" data-name-nudge=".5,0" aria-label="{{ __('تحريك الاسم لليمين') }}">→</button><button type="button" class="button secondary small" data-name-nudge="0,.5" aria-label="{{ __('تحريك الاسم للأسفل') }}">↓</button></div><button type="button" class="text-link" data-name-position-reset>{{ __('إرجاع المكان الافتراضي') }}</button></div>
            <p class="tool-hint">{{ __('الخط المتقطع يوضح حدود الأمان، وما ينطبع. مكان الاسم يطبق على الجهات المطبوعة.') }}</p><p class="tool-hint" id="name-position-status" data-name-position-status role="status" aria-live="polite"></p>
        </section>
    @endif
    <a href="/guide" class="text-link">{{ __('لم أجد شكل بطاقتي') }} <x-icon name="card" size="17"/></a>
    <div id="ready-summary" class="notice" role="status">{{ __('اختار القالب وخيارات الطلب لحساب الإجمالي.') }}</div>
    <div id="ready-error" role="alert"></div>
    <button type="submit" id="ready-add-to-cart" class="button primary wide" disabled>{{ $cartItem?__('تحديث خيارات الطلب'):__('ضيف للسلة') }} <x-icon name="bag" size="18"/></button>
    <p class="tool-hint">{{ __('السعر النهائي حسب الجهات والخامة والتشطيب والكمية. التوصيل يُحسب عند إتمام الطلب.') }}</p>
</form>
