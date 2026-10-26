<?php

namespace App\Http\Controllers;

use App\Jobs\ExportOrderItem;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\CardTemplate;
use App\Models\Collection;
use App\Models\Finish;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionFile;
use App\Models\ReviewRequest;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\AdminResources;
use App\Services\AssetService;
use App\Services\CatalogService;
use App\Services\OrderWorkflow;
use App\Services\StoreSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    private function admin(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
    }

    private function log(string $action, string $type, string|int $id, array $changes = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => (string) $id, 'changes' => $changes]);
    }

    public function dashboard(): View|RedirectResponse
    {
        if (auth()->user()->role !== 'admin') {
            return redirect()->route('admin.tasks.index');
        }
        $orders = Order::with('items')->latest()->limit(6)->get();
        $all = Order::all();

        return view('admin.dashboard', ['orders' => $orders, 'count' => $all->count(), 'sales' => $all->where('status', 'delivered')->sum(fn ($o) => $o->totals['total_iqd']), 'pending' => $all->whereIn('status', ['new', 'pending_confirmation', 'design_review'])->count(), 'failed' => OrderItem::where('export_status', 'failed')->count(), 'reports' => $all->where('status', 'delivered')->groupBy(fn ($o) => $o->address['governorate'] ?? '')->map(fn ($g) => ['count' => $g->count(), 'total' => $g->sum(fn ($o) => $o->totals['total_iqd'])]), 'typeReports' => OrderItem::whereHas('order', fn ($q) => $q->where('status', 'delivered'))->get()->groupBy(fn ($i) => $i->snapshot['options']['mode'])->map->count()]);
    }

    public function orders(Request $r): View
    {
        $q = Order::query();
        $stages = OrderWorkflow::stages();
        $stage = $r->validate(['stage' => 'nullable|in:orders,production,shipping'])['stage'] ?? null;
        if ($stage) {
            abort_unless(auth()->user()->role === 'admin' || auth()->user()->role === $stage, 403);
            $q->whereIn('status', $stages[$stage]['statuses']);
        }
        if (auth()->user()->role === 'shipping') {
            $q->whereIn('status', ['ready_to_ship', 'shipped', 'delivered', 'returned']);
        }
        if ($r->filled('q')) {
            $q->where('number', 'like', '%'.$r->string('q')->limit(100).'%');
        }if (array_key_exists($r->input('status', ''), config('sticker.statuses'))) {
            $q->where('status', $r->input('status'));
        }if (in_array($r->input('financial'), ['unpaid', 'partial', 'paid', 'refunded'])) {
            $q->where('financial_status', $r->input('financial'));
        }$r->input('sort') === 'oldest' ? $q->oldest() : $q->latest();

        return view('admin.orders', ['orders' => $q->paginate(20)->withQueryString()]);
    }

    public function order(Order $order): View
    {
        abort_unless(OrderWorkflow::canView($order, auth()->user()), 404);
        $order->load('items.files', 'customer', 'shippingReceiver');
        $workflowEvents = AuditLog::where('subject_type', 'Order')->where('subject_id', $order->id)
            ->whereIn('action', ['order.status', 'order.shipping_received', 'production.download_requested'])
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->select('audit_logs.*', 'users.name as actor_name')->latest('audit_logs.id')->limit(20)->get();

        return view('admin.order', compact('order', 'workflowEvents'));
    }

    public function status(Request $r, Order $order): RedirectResponse
    {
        $data = $r->validate(['status' => 'required|in:'.implode(',', array_keys(config('sticker.statuses')))]);
        $next = $data['status'];
        $order = DB::transaction(function () use ($order, $next) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $allowed = OrderWorkflow::nextStatuses($order, auth()->user());
            if (! in_array($next, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'انتقال الحالة غير متاح لهذا الدور أو لهذه المرحلة.']);
            }
            if ($next === 'shipped' && ! $order->shipping_received_at) {
                throw ValidationException::withMessages(['status' => __('سجّل استلام الطلب الجاهز لدى مسؤول التوصيل أولاً.')]);
            }
            if ($next === 'approved') {
                foreach ($order->items as $item) {
                    foreach ($item->snapshot['templates'] as $t) {
                        if (empty($t['approved_at'])) {
                            throw ValidationException::withMessages(['status' => 'قالب الطلب غير معتمد.']);
                        }
                    }
                }
            }
            if (in_array($next, ['printing', 'ready_to_ship']) && $order->items()->where('export_status', '!=', 'ready')->exists()) {
                throw ValidationException::withMessages(['status' => 'ملفات الإنتاج لم تجهز بنجاح بعد.']);
            }$old = $order->status;
            $changes = ['status' => $next];
            if ($order->status === 'ready_to_ship' && $next === 'design_review') {
                $changes += ['shipping_received_at' => null, 'shipping_received_by' => null];
            }
            $order->update($changes);
            $this->log('order.status', 'Order', $order->id, ['from' => $old, 'to' => $next]);
            if ($next === 'approved') {
                foreach ($order->items as $item) {
                    $item->update(['export_status' => 'pending']);
                    ExportOrderItem::dispatch($item->id)->afterCommit();
                }
            }

            return $order;
        });

        return redirect('/admin/orders/'.$order->id)->with('success', 'تم تحديث حالة الطلب.');
    }

    public function payment(Request $r, Order $order): RedirectResponse
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'orders']), 403);
        $d = $r->validate(['amount_iqd' => 'required|integer|min:1', 'reference' => 'required|string|max:150']);
        DB::transaction(function () use ($d, $order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $collected = Collection::netFor($order->id);
            if (Collection::where('order_id', $order->id)->where('method', 'cod_manual')->where('reference', $d['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => 'مرجع التحصيل مسجل مسبقاً.']);
            }if ($order->totals['total_iqd'] < $d['amount_iqd'] + $collected) {
                throw ValidationException::withMessages(['amount_iqd' => 'المبلغ يتجاوز رصيد الطلب.']);
            }Collection::create($d + ['order_id' => $order->id, 'method' => 'cod_manual', 'recorded_by' => auth()->id()]);
            $order->update(['financial_status' => $order->totals['total_iqd'] === $d['amount_iqd'] + $collected ? 'paid' : 'partial']);
            $this->log('payment.recorded', 'Order', $order->id, ['amount_iqd' => $d['amount_iqd']]);
        });

        return redirect('/admin/orders/'.$order->id)->with('success', 'تم تسجيل التحصيل.');
    }

    public function refund(Request $r, Order $order): RedirectResponse
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'orders']), 403);
        $data = $r->validate(['amount_iqd' => 'required|integer|min:1', 'reference' => 'required|string|max:150']);
        DB::transaction(function () use ($order, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['cancelled', 'returned'])) {
                throw ValidationException::withMessages(['refund' => 'سجل الإلغاء أو الإرجاع قبل تسجيل إعادة المبلغ.']);
            }$net = Collection::netFor($order->id);
            if ($data['amount_iqd'] > $net) {
                throw ValidationException::withMessages(['amount_iqd' => 'المبلغ المعاد يتجاوز صافي التحصيل.']);
            }if (Collection::where('order_id', $order->id)->where('method', 'refund')->where('reference', $data['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => 'مرجع إعادة المبلغ مسجل مسبقاً.']);
            }Collection::create($data + ['order_id' => $order->id, 'method' => 'refund', 'recorded_by' => auth()->id()]);
            $order->update(['financial_status' => $net - $data['amount_iqd'] === 0 ? 'refunded' : 'partial']);
            $this->log('payment.refunded', 'Order', $order->id, ['amount_iqd' => $data['amount_iqd']]);
        });

        return redirect('/admin/orders/'.$order->id)->with('success', 'تم تسجيل المبلغ المعاد فعلياً.');
    }

    public function retry(OrderItem $item): RedirectResponse
    {
        abort_unless(OrderWorkflow::canDownload(auth()->user()), 403);
        abort_if(in_array($item->export_status, ['processing', 'pending', 'expired']), 409);
        $item->update(['export_status' => 'pending']);
        ExportOrderItem::dispatch($item->id);
        $this->log('export.retry', 'OrderItem', $item->id);

        return redirect('/admin/orders/'.$item->order_id)->with('success', 'أضيف التصدير إلى قائمة الانتظار.');
    }

    public function file(ProductionFile $file): StreamedResponse
    {
        abort_unless(OrderWorkflow::canDownload(auth()->user()), 403);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        $orderId = OrderItem::findOrFail($file->order_item_id)->order_id;
        $this->log('production.download_requested', 'Order', $orderId, ['file_id' => $file->id, 'kind' => $file->kind, 'side' => $file->side]);

        return Storage::disk('local')->download($file->path, basename($file->path), ['Cache-Control' => 'private, no-store']);
    }

    public function itemAsset(OrderItem $item, string $asset): BinaryFileResponse
    {
        abort_if(auth()->user()->role === 'shipping', 403);
        $a = $item->snapshot['assets'][$asset] ?? null;
        abort_unless($a, 404);

        return response()->file(Storage::disk('local')->path($a['path']), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    public function itemArt(OrderItem $item, string $key): BinaryFileResponse
    {
        abort_if(auth()->user()->role === 'shipping', 403);
        $a = $item->snapshot['art_assets'][$key] ?? null;
        abort_unless($a, 404);

        return response()->file(Storage::disk('local')->path($a['path']), ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store']);
    }

    public function media(Asset $asset): BinaryFileResponse
    {
        abort_if(auth()->user()->role === 'shipping', 403);

        return response()->file(Storage::disk('local')->path($asset->path), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    public function resource(Request $r, string $resource, ?int $id = null): View
    {
        $this->admin();
        $spec = AdminResources::get($resource);
        $model = $spec['model'];
        $q = $model::query();
        if ($resource === 'products') {
            $q->with('category', 'categories', 'section');
            if ($r->boolean('source_review')) {
                $q->whereNotNull('catalog_metadata->review_reason');
            }
        }
        if ($r->filled('q')) {
            $field = array_key_first($spec['fields']);
            $q->where($field, 'like', '%'.$r->string('q')->limit(100).'%');
        }

        return view('admin.resource', ['resource' => $resource, 'spec' => $spec, 'records' => $q->latest()->paginate(15)->withQueryString(), 'record' => $id ? $model::findOrFail($id) : null]);
    }

    public function saveResource(Request $r, string $resource, ?int $id = null): RedirectResponse
    {
        $this->admin();
        $spec = AdminResources::get($resource);
        $rules = [];
        foreach ($spec['fields'] as $key => $field) {
            $rules[$key] = $field[2];
            if ($field[1] === 'checkbox') {
                $r->merge([$key => $r->boolean($key)]);
            }
        }$data = $r->validate($rules);
        $model = $spec['model'];
        $record = $id ? $model::findOrFail($id) : new $model;
        $slugQuery = $resource === 'products' ? $model::withTrashed() : $model::query();
        if (isset($data['slug']) && $slugQuery->where('slug', $data['slug'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['slug' => 'الرابط مستخدم.']);
        }if ($resource === 'coupons') {
            if ($data['type'] === 'percent' && $data['value'] > 100) {
                throw ValidationException::withMessages(['value' => 'النسبة لا تتجاوز 100.']);
            }$data['code'] = strtoupper($data['code']);
        }
        if ($resource === 'shipping' && ! empty($data['area_id']) && Area::find($data['area_id'])->governorate_id != (int) $data['governorate_id']) {
            throw ValidationException::withMessages(['area_id' => 'المنطقة لا تتبع المحافظة المختارة.']);
        }if ($resource === 'products') {
            $r->validate(['art_file' => 'nullable|file|max:'.(config('sticker.max_catalog_upload_mb') * 1024)], ['art_file.max' => __('الحد الأعلى لصورة الكتالوج 4 ميغابايت.'), 'art_file.uploaded' => __('تعذر رفع صورة التصميم. أعد المحاولة بملف حتى 4 ميغابايت.')]);
        }if ($resource === 'products' && $r->hasFile('art_file')) {
            $asset = app(AssetService::class)->upload($r->file('art_file'));
            $catalog = app(CatalogService::class)->publish($asset);
            $data['catalog_asset_id'] = $catalog->id;
        }if ($resource === 'products' && empty($data['art_key']) && empty($data['catalog_asset_id']) && empty($record->catalog_asset_id)) {
            throw ValidationException::withMessages(['art_file' => 'اختر رسماً أو ارفع صورة تصميم مرخصة.']);
        }if ($resource === 'bundles' && ($data['discount_percent'] > 100 || $data['minimum_quantity'] < 2)) {
            throw ValidationException::withMessages(['discount_percent' => 'نسبة الباقة حتى 100%، والحد الأدنى قطعتان.']);
        }$uniqueField = match ($resource) {
            'materials' => 'code','coupons' => 'code','governorates' => 'name',default => null
        };
        if ($uniqueField && $model::where($uniqueField, $data[$uniqueField])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages([$uniqueField => 'القيمة مستخدمة مسبقاً.']);
        }if ($resource === 'shipping' && $model::where('governorate_id', $data['governorate_id'])->where('area_id', $data['area_id'] ?? null)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['governorate_id' => 'سعر توصيل لهذه التغطية موجود بالفعل. عدّل السجل الموجود.']);
        }$record->fill($data)->save();
        $this->log('resource.saved', $resource, $record->id, $data);

        return redirect('/admin/manage/'.$resource.'/'.$record->id)->with('success', 'تم حفظ البيانات.');
    }

    public function featureProduct(Request $request, Product $product): RedirectResponse
    {
        $this->admin();
        $data = $request->validate(['is_featured' => 'required|boolean']);
        DB::transaction(function () use ($product, $data): void {
            $record = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $record->update(['is_featured' => (bool) $data['is_featured']]);
            $this->log('product.featured_updated', 'products', $record->id, ['is_featured' => $record->is_featured]);
        });

        return back()->with('success', __('تم تحديث تمييز التصميم.'));
    }

    public function deleteProduct(Product $product): RedirectResponse
    {
        $this->admin();
        DB::transaction(function () use ($product): void {
            $record = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $record->update(['active' => false, 'is_featured' => false]);
            $record->delete();
            $this->log('product.deleted', 'products', $record->id, ['name' => $record->name]);
        });

        return redirect()->route('admin.resources.show', ['resource' => 'products'])->with('success', __('تم حذف التصميم من المتجر. تبقى نسخه في الطلبات السابقة محفوظة.'));
    }

    public function templates(): View
    {
        $this->admin();

        return view('admin.templates', ['templates' => CardTemplate::with('versions')->get(), 'materials' => Material::all(), 'finishes' => Finish::all()]);
    }

    public function template(Request $r): RedirectResponse
    {
        $this->admin();
        $d = $r->validate(['name' => 'required|string|max:100', 'side' => 'required|in:front,back', 'kind' => 'required_if:side,front|nullable|in:small_chip,no_chip', 'template_id' => 'nullable|integer|exists:card_templates,id', 'geometry' => 'required|json|max:10000', 'compatibility' => 'required|json|max:10000', 'sample_reference' => 'nullable|string|max:200', 'physical_approval' => 'sometimes|accepted', 'active' => 'nullable|boolean']);
        $g = json_decode($d['geometry'], true);
        $c = json_decode($d['compatibility'], true);
        validator($g, ['card_width' => 'required|numeric|between:20,100', 'card_height' => 'required|numeric|between:20,100', 'width' => 'required|numeric|between:20,100', 'height' => 'required|numeric|between:20,100', 'radius' => 'required|numeric|between:0,10', 'bleed' => 'required|numeric|between:0,5', 'safe' => 'required|numeric|between:1,10', 'holes' => 'present|array|max:10', 'holes.*.x' => 'required|numeric|min:0', 'holes.*.y' => 'required|numeric|min:0', 'holes.*.width' => 'required|numeric|min:0.1', 'holes.*.height' => 'required|numeric|min:0.1', 'holes.*.radius' => 'required|numeric|between:0,5'])->validate();
        if ($g['width'] > $g['card_width'] || $g['height'] > $g['card_height'] || $g['radius'] > min($g['width'], $g['height']) / 2) {
            throw ValidationException::withMessages(['geometry' => 'أبعاد الستيكر غير متوافقة مع البطاقة.']);
        }foreach ($g['holes'] as $h) {
            if ($g['width'] < $h['x'] + $h['width'] || $g['height'] < $h['y'] + $h['height']) {
                throw ValidationException::withMessages(['geometry' => 'فتحة خارج حدود الستيكر.']);
            }
        }
        if ($d['side'] === 'front' && (($d['kind'] === 'no_chip' && count($g['holes']) !== 0) || ($d['kind'] === 'small_chip' && count($g['holes']) === 0))) {
            throw ValidationException::withMessages(['geometry' => __('فتحات القالب لا تطابق خيار الشريحة المختار.')]);
        }
        validator($c, ['modes' => 'required|array|min:1', 'modes.*' => 'required|in:single,double,mixed', 'material_ids' => 'present|array', 'material_ids.*' => 'required|integer|exists:materials,id', 'finish_ids' => 'present|array', 'finish_ids.*' => 'required|integer|exists:finishes,id'])->validate();
        if ($r->boolean('physical_approval') && empty($d['sample_reference'])) {
            throw ValidationException::withMessages(['sample_reference' => 'مرجع عينة الطباعة مطلوب للاعتماد.']);
        }
        DB::transaction(function () use ($d, $g, $c, $r) {
            $kind = $d['side'] === 'front' ? $d['kind'] : null;
            $t = empty($d['template_id']) ? CardTemplate::create(['name' => $d['name'], 'side' => $d['side'], 'kind' => $kind, 'active' => $r->boolean('active')]) : CardTemplate::whereKey($d['template_id'])->lockForUpdate()->firstOrFail();
            if ($t->side !== $d['side']) {
                throw ValidationException::withMessages(['side' => 'أنشئ قالباً مستقلاً للجهة الأخرى.']);
            }
            if ($t->kind !== $kind) {
                throw ValidationException::withMessages(['kind' => __('نوع الشريحة يحتاج قالباً مستقلاً. لا تغيّر نوع قالب موجود.')]);
            }
            $v = TemplateVersion::create(['card_template_id' => $t->id, 'version' => ($t->versions()->max('version') ?? 0) + 1, 'geometry' => $g, 'compatibility' => $c, 'approved_at' => $r->boolean('physical_approval') ? now() : null, 'approved_by' => $r->boolean('physical_approval') ? auth()->id() : null, 'sample_reference' => $d['sample_reference'] ?? null]);
            $t->update(['active' => $r->boolean('active')]);
            $this->log('template.version_created', 'TemplateVersion', $v->id, ['sample_reference' => $v->sample_reference]);
        });

        return redirect('/admin/templates')->with('success', 'تم إنشاء إصدار مستقل للقالب.');
    }

    public function settings(): View
    {
        $this->admin();

        return view('admin.settings', ['hasPrintableMaterial' => Material::where('active', true)->where('transparent', false)->exists(), 'hasApprovedTemplate' => TemplateVersion::whereNotNull('approved_at')->whereHas('template', fn ($query) => $query->where('active', true))->exists()]);
    }

    public function saveSettings(Request $r): RedirectResponse
    {
        $this->admin();
        $d = $r->validate(['logo_variant' => 'nullable|in:icon,icon-mono', 'instagram' => 'nullable|url:https|max:200', 'whatsapp' => 'nullable|regex:/^\+?[0-9]{10,15}$/', 'contact_email' => 'nullable|email|max:200', 'contact_address' => 'nullable|string|max:500', 'price_single_iqd' => 'nullable|integer|min:0|max:100000000', 'price_double_iqd' => 'nullable|integer|min:0|max:100000000', 'price_mixed_iqd' => 'nullable|integer|min:0|max:100000000', 'extra_double_iqd' => 'nullable|integer|min:0|max:100000000', 'extra_mixed_iqd' => 'nullable|integer|min:0|max:100000000', 'ppi' => 'required|integer|between:72,600', 'faq' => 'required|json|max:15000']);
        $faq = json_decode($d['faq'], true);
        validator(['faq' => $faq], ['faq' => 'required|array|max:30', 'faq.*.q' => 'required|string|max:250', 'faq.*.a' => 'required|string|max:2000'])->validate();
        foreach ($d as $key => $v) {
            if (! in_array($key, ['ppi', 'faq'])) {
                StoreSettings::put($key, str_contains($key, '_iqd') && $v !== null ? (int) $v : $v);
            }
        }StoreSettings::put('faq', $faq);
        StoreSettings::put('accepting_orders', $r->boolean('accepting_orders'));
        StoreSettings::put('cod_enabled', $r->boolean('cod_enabled'));
        StoreSettings::put('print_profile', ['color_space' => 'sRGB', 'ppi' => (int) $d['ppi'], 'pdf_standard' => 'PDF', 'icc' => null, 'white_ink' => false, 'mirror' => false]);
        $this->log('settings.updated', 'Store', 'main');

        return redirect('/admin/settings')->with('success', 'تم حفظ إعدادات المتجر.');
    }

    public function users(): View
    {
        $this->admin();

        return view('admin.users', ['users' => User::latest()->paginate(20)]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $this->admin();
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|string|email|max:200|unique:users,email',
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && config('hashing.driver') === 'bcrypt' && strlen($value) > 72) {
                    $fail(__('كلمة المرور طويلة جداً. استخدم كلمة أقصر.'));
                }
            }],
            'role' => 'required|in:'.implode(',', array_keys(User::ROLES)),
        ], [
            'email.unique' => __('هذا البريد مستخدم مسبقاً. اختر بريداً آخر.'),
            'password.min' => __('كلمة المرور يجب أن تكون 12 حرفاً على الأقل.'),
            'password.confirmed' => __('تأكيد كلمة المرور غير مطابق.'),
        ]);
        $user = DB::transaction(function () use ($data): User {
            $user = new User(collect($data)->only(['name', 'email', 'password'])->all());
            $user->role = $data['role'];
            $user->save();
            $this->log('user.created', 'User', (string) $user->id, ['role' => $user->role]);

            return $user;
        });

        return redirect()->route('admin.users.index')->with('success', __('تمت إضافة المستخدم :name.', ['name' => $user->name]));
    }

    public function user(Request $r, User $user): RedirectResponse
    {
        $this->admin();
        $d = $r->validate(['role' => 'required|in:'.implode(',', array_keys(User::ROLES))]);
        if ($user->id === auth()->id() && $d['role'] !== 'admin') {
            throw ValidationException::withMessages(['role' => 'لا يمكنك إزالة صلاحية المدير من حسابك الحالي.']);
        }$user->role = $d['role'];
        $user->save();
        $this->log('user.role_changed', 'User', $user->id, $d);

        return redirect('/admin/users')->with('success', 'تم تحديث الصلاحية.');
    }

    public function logs(): View
    {
        $this->admin();

        return view('admin.logs', ['logs' => AuditLog::latest()->paginate(30)]);
    }

    public function reviews(): View
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'orders']), 403);

        return view('admin.reviews', ['reviews' => ReviewRequest::latest()->paginate(20)]);
    }

    public function review(Request $r, ReviewRequest $review): RedirectResponse
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'orders']), 403);
        $d = $r->validate(['status' => 'required|in:new,in_review,resolved']);
        $review->update($d);
        $this->log('review.updated', 'ReviewRequest', $review->id, $d);

        return redirect('/admin/reviews')->with('success', 'تم تحديث المراجعة.');
    }
}
