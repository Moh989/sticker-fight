<x-admin-layout :title="$spec['label']">
    <div class="admin-heading">
        <div><h1>{{ $spec['label'] }}</h1><p>{{ __('المعلومات التجارية تظهر للزبون حسب حالة التفعيل والنشر.') }}</p></div>
        <a href="{{ route('admin.resources.show',['resource'=>$resource]) }}" class="button primary small">{{ __('إضافة جديد') }} <x-icon name="plus" size="16"/></a>
    </div>
    <div class="admin-two-col">
        <section>
            <form method="get" class="catalog-filters"><input name="q" placeholder="{{ __('بحث') }}" value="{{ request('q') }}" aria-label="{{ __('بحث') }}"><button class="button secondary small">{{ __('بحث') }}</button></form>
            <div class="table-scroll">
                <table @if($resource==='products') class="admin-products-table" @endif>
                    <thead><tr><th>#</th>@if($resource==='products')<th>{{ __('معاينة الصورة') }}</th><th>{{ __('التصميم') }}</th><th>{{ __('السعر / د.ع') }}</th>@else @foreach(array_slice($spec['fields'],0,3) as $key=>$field)<th>{{ __($field[0]) }}</th>@endforeach @endif<th>{{ __('الإجراءات') }}</th></tr></thead>
                    <tbody>
                        @forelse($records as $row)
                            <tr data-product-id="{{ $resource==='products'?$row->id:'' }}">
                                <td>{{ $row->id }}</td>
                                @if($resource==='products')<td><a href="{{ $row->preview_image_url }}" target="_blank" rel="noopener" aria-label="{{ __('معاينة :name',['name'=>$row->name]) }}"><img class="admin-product-thumb" src="{{ $row->preview_image_url }}" alt="{{ $row->name }}" width="86" height="54" loading="lazy"></a></td>@endif
                                @if($resource==='products')
                                    <td class="admin-product-name"><a href="{{ route('admin.resources.show',['resource'=>$resource,'id'=>$row->id]) }}">{{ $row->name }}</a><small dir="ltr">{{ $row->slug }}</small>@if($row->category)<small>{{ $row->category->name }}</small>@endif @if($row->is_featured)<span class="featured-badge"><x-icon name="sparkles" size="13"/>{{ __('تصميم مميز') }}</span>@endif</td>
                                    <td>{{ $row->base_price_iqd===null?__('السعر غير محدد'):number_format($row->base_price_iqd) }}<br><small class="muted">{{ $row->price_iqd===null?__('السعر العام'):__('سعر خاص') }}</small></td>
                                @else
                                    @foreach(array_slice($spec['fields'],0,3) as $key=>$field)<td>{{ is_bool($row->$key)?($row->$key?__('نعم'):__('لا')):$row->$key }}</td>@endforeach
                                @endif
                                <td><div class="admin-product-actions">
                                    <a class="text-link" href="{{ route('admin.resources.show',['resource'=>$resource,'id'=>$row->id]) }}">{{ __('فتح ↗') }}</a>
                                    @if($resource==='products')
                                        <form method="post" action="{{ route('admin.products.feature',$row) }}">@csrf<input type="hidden" name="is_featured" value="{{ $row->is_featured?0:1 }}"><button class="button secondary small" aria-label="{{ $row->is_featured?__('إلغاء التمييز'):__('تمييز التصميم') }}" aria-pressed="{{ $row->is_featured?'true':'false' }}"><x-icon name="sparkles" size="15"/>{{ $row->is_featured?__('إلغاء التمييز'):__('تمييز') }}</button></form>
                                        <form method="post" action="{{ route('admin.products.destroy',$row) }}" data-confirm-delete="{{ __('تحذف هذا التصميم من المتجر؟ تبقى نسخه في الطلبات السابقة محفوظة.') }}">@csrf @method('DELETE')<button class="button secondary small danger-button" aria-label="{{ __('حذف التصميم') }}"><x-icon name="trash" size="15"/>{{ __('حذف') }}</button></form>
                                    @endif
                                </div></td>
                            </tr>
                        @empty<tr><td colspan="5">{{ __('لا توجد بيانات بعد.') }}</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
            {{ $records->links() }}
        </section>
        <section class="panel">
            <h2>{{ $record?__('تعديل').' #'.$record->id:__('إضافة سجل') }}</h2>
            <form method="post" action="{{ route('admin.resources.save',['resource'=>$resource,'id'=>$record?->id]) }}" class="resource-form" enctype="multipart/form-data" @if($resource==='products') data-product-art-form data-catalog-preview="{{ $record?->catalog_asset_id?$record->preview_image_url:'' }}" @endif>
                @csrf
                @foreach($spec['fields'] as $key=>$field)
                    @php $value=old($key,$record?->$key); @endphp
                    @if($field[1]==='checkbox')<label class="checkbox-label"><input type="checkbox" name="{{ $key }}" value="1" @checked($value)>{{ __($field[0]) }}</label>
                    @else
                        <label>{{ __($field[0]) }}
                            @if($field[1]==='textarea')<textarea name="{{ $key }}" rows="5">{{ $value }}</textarea>
                            @elseif($field[1]==='material_kind')<select name="{{ $key }}" required><option value="0" @selected(!$value)>{{ __('خامة مطبوعة') }}</option><option value="1" @selected($value)>{{ __('شفاف غير مطبوع') }}</option></select>
                            @elseif(in_array($field[1],['category','art','governorate','area','coupon_type']))
                                <select name="{{ $key }}"><option value="">{{ __('اختار') }}</option>
                                    @php $options=match($field[1]){'category'=>\App\Models\Category::pluck('name','id'),'governorate'=>\App\Models\Governorate::pluck('name','id'),'area'=>\App\Models\Area::pluck('name','id'),'coupon_type'=>collect(['fixed'=>'مبلغ ثابت','percent'=>'نسبة مئوية']),'art'=>collect(\App\Services\DesignValidator::ARTS)->mapWithKeys(fn($s)=>[$s=>$s])}; @endphp
                                    @foreach($options as $id=>$label)<option value="{{ $id }}" @selected((string)$value===(string)$id)>{{ __($label) }}</option>@endforeach
                                </select>
                            @else<input name="{{ $key }}" type="{{ $field[1] }}" value="{{ $value }}" @if($field[1]==='number') min="0" step="1" @endif @if(in_array($key,['slug','code','art_key'])) dir="ltr" @endif>
                            @endif
                            @if($resource==='products'&&$key==='price_iqd')<small class="muted">{{ __('السعر الخاص للوجه الواحد. اتركه فارغاً لاستخدام الأسعار العامة حسب الجهات.') }}</small>@endif
                        </label>
                    @endif
                @endforeach
                @if($resource==='products')
                    <label>{{ __('رفع تصميم أصلي أو مرخص (اختياري)') }}<input type="file" name="art_file" accept="image/jpeg,image/png,image/webp" data-max-bytes="{{ config('sticker.max_upload_mb')*1024*1024 }}"><small class="muted">{{ __('نسخة عرض محسنة وأصل خاص منفصل للإنتاج.') }}</small></label>
                    <figure class="admin-art-preview" data-art-preview-box @unless($record) hidden @endunless>
                        <img data-art-preview @if($record) src="{{ $record->preview_image_url }}" @endif alt="{{ __('معاينة صورة التصميم') }}">
                        <figcaption>{{ __('معاينة الصورة المرفوعة') }}</figcaption>
                    </figure>
                    <p data-art-preview-error class="notice error" role="alert" hidden></p>
                @endif
                <button class="button primary">{{ __('حفظ البيانات') }} <x-icon name="check" size="17"/></button>
            </form>
            @if($resource==='products'&&$record)
                <form class="admin-delete-design" method="post" action="{{ route('admin.products.destroy',$record) }}" data-confirm-delete="{{ __('تحذف هذا التصميم من المتجر؟ تبقى نسخه في الطلبات السابقة محفوظة.') }}">@csrf @method('DELETE')<button class="button secondary danger-button wide"><x-icon name="trash" size="17"/>{{ __('حذف التصميم') }}</button></form>
            @endif
        </section>
    </div>
</x-admin-layout>
