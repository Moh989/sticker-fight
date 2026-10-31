@if($customItems->isNotEmpty())
    <div class="checkout-publication" role="group" aria-labelledby="publication-heading">
        <h3 id="publication-heading"><span class="iraqi-star" aria-hidden="true"></span>{{ __('هل توافق على نشر تصميمك في موقعنا؟') }}</h3>
        <p class="tool-hint">{{ __('اختياري. إذا وافقت، ننشر صورة التصميم كاملة ضمن حدود البطاقة وقبل فتحات القصّ، والكتابة اللي داخله، ضمن «تصاميمكم اللطيفة». بدون بيانات طلبك أو معلومات التواصل. الموافقة تشمل الجهات المطبوعة. اختيار لا ما يأثر على إتمام الطلب.') }}</p>
        @foreach($customItems as $customItem)
            <fieldset class="publication-choice" data-publication-item="{{ $customItem->id }}">
                <legend>{{ $customItem->version->design->name }}</legend>
                <div class="choice-cards">
                    <label class="choice-card"><input type="radio" name="publication_choices[{{ $customItem->id }}]" value="1"><span>{{ __('نعم، موافق على نشر هذا التصميم') }}</span></label>
                    <label class="choice-card"><input type="radio" name="publication_choices[{{ $customItem->id }}]" value="0" checked><span>{{ __('لا، خلي هذا التصميم خاص') }}</span></label>
                </div>
            </fieldset>
        @endforeach
    </div>
@endif
