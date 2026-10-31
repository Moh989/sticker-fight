<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CatalogRequest;
use App\Models\DeviceModel;
use App\Models\ProductSection;
use App\Services\AssetService;
use App\Services\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogRequestController extends Controller
{
    public function store(Request $request, ProductSection $section): RedirectResponse
    {
        abort_unless($section->active, 404);
        $data = $request->validate([
            'request_id' => 'required|uuid', 'workspace' => 'required|string|size:64',
            'name' => 'required|string|max:100', 'phone' => 'required|string|max:30',
            'model' => [Rule::requiredIf($section->requires_model && ! $request->filled('device_model_id')), 'nullable', 'string', 'max:150'],
            'product_id' => 'nullable|integer', 'quantity' => 'required|integer|between:1,100000',
            'width_mm' => 'nullable|required_with:height_mm|numeric|between:1,10000|decimal:0,2',
            'height_mm' => 'nullable|required_with:width_mm|numeric|between:1,10000|decimal:0,2',
            'details' => 'required|string|min:10|max:3000',
            'device_model_id' => [Rule::requiredIf($section->requires_model && $section->deviceModels()->where('active', true)->exists()), 'nullable', 'integer'],
            'shape' => 'nullable|string|max:100', 'material' => 'nullable|string|max:150',
            'attachment' => 'nullable|file|max:14336', 'rights' => ['nullable', Rule::when($request->hasFile('attachment'), ['required', 'accepted'])],
        ], [
            'required' => __('حقل :attribute مطلوب.'),
            'model.required' => __('اكتب موديل الجهاز بالتحديد.'),
            'quantity.between' => __('الكمية من 1 إلى 100000 قطعة.'),
            'details.min' => __('اكتب تفاصيل الفكرة بعشر حروف على الأقل.'),
            'width_mm.required_with' => __('أكمل العرض والارتفاع معاً، أو اترك المقاس فارغاً.'),
            'height_mm.required_with' => __('أكمل العرض والارتفاع معاً، أو اترك المقاس فارغاً.'),
        ], ['name' => __('الاسم'), 'phone' => __('رقم الهاتف'), 'quantity' => __('الكمية المطلوبة'), 'details' => __('تفاصيل فكرتك')]);
        abort_unless(hash_equals((string) $request->attributes->get('guest_workspace'), $data['workspace']), 409, __('تغيّرت جلسة الزائر. افتح الصفحة من جديد قبل إرسال الطلب.'));
        $product = empty($data['product_id']) ? null : $section->products()->where('active', true)->findOrFail($data['product_id']);
        $model = empty($data['device_model_id']) ? null : DeviceModel::where('active', true)->where('product_section_id', $section->id)->findOrFail($data['device_model_id']);
        if ($product && $product->deviceModels()->exists() && (! $model || ! $product->deviceModels()->whereKey($model->id)->exists())) {
            throw ValidationException::withMessages(['device_model_id' => __('هذا التصميم لا يتوافق مع الموديل المختار.')]);
        }
        $template = $model?->templates()->where('active', true)->whereNotNull('approved_at')->orderByDesc('version')->first();
        $asset = $request->hasFile('attachment') ? app(AssetService::class)->upload($request->file('attachment')) : null;
        $payload = [
            'kind' => $section->audience === 'business' ? 'wholesale' : 'device',
            'device_model_id' => $model?->id, 'device_template_id' => $template?->id,
            'asset_id' => $asset?->id, 'shape' => $data['shape'] ?? null, 'material' => $data['material'] ?? null,
            'product_section_id' => $section->id, 'product_id' => $product?->id,
            'name' => trim($data['name']), 'phone' => Phone::normalize($data['phone']),
            'model' => $model?->name ?? (filled($data['model'] ?? null) ? trim($data['model']) : null),
            'quantity' => (int) $data['quantity'],
            'width_mm' => isset($data['width_mm']) ? number_format((float) $data['width_mm'], 2, '.', '') : null,
            'height_mm' => isset($data['height_mm']) ? number_format((float) $data['height_mm'], 2, '.', '') : null,
            'details' => trim($data['details']),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $record = CatalogRequest::firstOrCreate(['request_id' => $data['request_id']], $payload + [
            'guest_session_id' => session('guest_id'), 'submission_hash' => $hash,
            'snapshot' => ['section' => $section->name, 'product' => $product?->name, 'device_model' => $model?->only(['id', 'name', 'manufacturer']), 'template' => $template?->only(['id', 'name', 'version', 'geometry', 'approved_at', 'sample_reference']), 'attachment' => $asset?->only(['id', 'path', 'sha256', 'width', 'height']), 'rights_confirmed_at' => $asset ? now()->toIso8601String() : null],
        ]);
        abort_unless($record->guest_session_id === session('guest_id'), 404);
        abort_unless(hash_equals($record->submission_hash, $hash), 409, __('أُرسل هذا الطلب سابقاً بتفاصيل مختلفة. حدّث الصفحة لطلب جديد.'));

        return redirect()->to(($section->audience === 'business' ? route('wholesale.index') : route('devices.section', $section->slug)).'#request')->with('success', __('وصل طلب التسعير :number. تراجع الإدارة التفاصيل قبل تأكيد السعر والتنفيذ.', ['number' => $record->number]));
    }

    public function index(Request $request): View
    {
        $this->authorizeStaff();
        $input = $request->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(array_keys(CatalogRequest::STATUSES))], 'section' => 'nullable|integer|exists:product_sections,id']);
        $requests = CatalogRequest::with('section')->latest();
        if (! empty($input['status'])) {
            $requests->where('status', $input['status']);
        }
        if (! empty($input['section'])) {
            $requests->where('product_section_id', $input['section']);
        }
        if (! empty($input['q'])) {
            $search = Str::startsWith(strtoupper($input['q']), 'SFQ-') ? substr($input['q'], 4) : $input['q'];
            $requests->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('model', 'like', '%'.$search.'%')->orWhere('id', 'like', '%'.$search.'%'));
        }

        return view('admin.catalog-requests', ['requests' => $requests->paginate(15)->withQueryString(), 'sections' => ProductSection::orderBy('sort_order')->get()]);
    }

    public function update(Request $request, CatalogRequest $catalogRequest): RedirectResponse
    {
        $this->authorizeStaff();
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(CatalogRequest::STATUSES))],
            'quoted_iqd' => 'nullable|required_if:status,quoted|integer|between:0,1000000000',
            'staff_notes' => 'nullable|string|max:3000',
        ], ['quoted_iqd.required_if' => __('أدخل سعر العرض الإجمالي قبل اختيار تم تحديد السعر.')]);
        DB::transaction(function () use ($data, $catalogRequest): void {
            $record = CatalogRequest::whereKey($catalogRequest->id)->lockForUpdate()->firstOrFail();
            $record->update($data);
            AuditLog::create(['user_id' => auth()->id(), 'action' => 'catalog_request.updated', 'subject_type' => 'CatalogRequest', 'subject_id' => $record->id, 'changes' => collect($data)->only(['status', 'quoted_iqd'])->all()]);
        });

        return back()->with('success', __('تم تحديث طلب التسعير.'));
    }

    public function attachment(CatalogRequest $catalogRequest): BinaryFileResponse
    {
        $this->authorizeStaff();
        $asset = $catalogRequest->snapshot['attachment'] ?? null;
        abort_unless($asset && Storage::disk('local')->exists($asset['path']), 404);

        return response()->file(Storage::disk('local')->path($asset['path']), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    private function authorizeStaff(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['admin', 'orders'], true), 403);
    }
}
