<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\DesignVersion;
use App\Models\GuestSession;
use App\Services\AssetService;
use App\Services\DesignValidator;
use App\Services\Ownership;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DesignController extends Controller
{
    public function save(Request $r, string $id, DesignValidator $validator): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $existing = Design::find($id);
        if ($existing) {
            Ownership::check($existing);
            abort_if(isset($existing->options['product_id']), 403, 'التصميم الجاهز ثابت ولا يقبل التعديل.');
        }
        $data = $validator->validate($r->all());
        $design = DB::transaction(function () use ($data, $id, $validator) {
            $d = Design::whereKey($id)->lockForUpdate()->first();
            if ($d) {
                Ownership::check($d);
                abort_if(isset($d->options['product_id']), 403, 'التصميم الجاهز ثابت ولا يقبل التعديل.');
                if ($d->document == $data['document'] && $d->options == $data['options'] && $d->name === $data['name']) {
                    return $d;
                }abort_if($d->revision !== $data['revision'], 409, 'التصميم تغير في نافذة أخرى. احتفظ بنسختك وأعد تحميل النسخة المحفوظة.');
            } else {
                abort_if($data['revision'] !== 0, 409);
                $d = new Design(['id' => $id, 'guest_session_id' => session('guest_id'), 'user_id' => auth()->id()]);
            }$d->fill(['name' => $data['name'], 'document' => $data['document'], 'options' => $data['options'], 'revision' => ($d->revision ?? 0) + 1])->save();
            DesignVersion::create(['design_id' => $d->id, 'revision' => $d->revision, 'document' => $d->document, 'options' => $d->options, 'templates' => $validator->templates($d->document)]);

            return $d;
        });

        return response()->json(['id' => $design->id, 'revision' => $design->revision, 'version_id' => $design->versions()->where('revision', $design->revision)->value('id'), 'saved_at' => $design->updated_at, 'recovery_url' => session('recovery_token') ? url('/restore/'.session('recovery_token')) : null]);
    }

    public function show(Design $design): JsonResponse
    {
        Ownership::check($design);

        return response()->json(['id' => $design->id, 'name' => $design->name, 'revision' => $design->revision, 'document' => $design->document, 'options' => $design->options]);
    }

    public function upload(Request $r, AssetService $service): JsonResponse
    {
        $r->validate(['file' => 'required|file|max:'.(config('sticker.max_upload_mb') * 1024), 'rights' => 'accepted'], ['file.max' => __('الحد الأعلى للصورة 14 ميغابايت.')]);
        $a = $service->upload($r->file('file'));
        $replacements = Asset::where('source', Asset::SOURCE_CATALOG)->where('sha256', $a->sha256)
            ->where(fn ($query) => $query->where('guest_session_id', session('guest_id'))->when(auth()->check(), fn ($owned) => $owned->orWhere('user_id', auth()->id())))->pluck('id');

        return response()->json(['id' => $a->id, 'url' => url('/media/'.$a->id), 'width' => $a->width, 'height' => $a->height, 'bytes' => $a->bytes, 'replaces_asset_ids' => $replacements], 201);
    }

    public function quote(Request $r, PricingService $pricing, DesignValidator $validator): JsonResponse
    {
        $data = $validator->validate($r->all());

        return response()->json($pricing->quote($data['options'], $validator->templates($data['document']), (int) $r->input('quantity', 1)));
    }

    public function cart(Request $r, PricingService $pricing): JsonResponse
    {
        $data = $r->validate(['design_id' => 'required|uuid', 'revision' => 'required|integer|min:1', 'quantity' => 'required|integer|min:1|max:100', 'cart_item_id' => 'nullable|uuid']);
        $d = Design::findOrFail($data['design_id']);
        Ownership::check($d);
        $v = $d->versions()->where('revision', $data['revision'])->firstOrFail();
        app(DesignValidator::class)->assertArtwork($v->document, $v->options);
        if (empty($v->options['ready_locked'])) {
            $r->validate(['reviewed' => 'accepted'], ['reviewed.accepted' => __('أكد مراجعة التصميم قبل إضافته للسلة.')]);
        }
        $pricing->quote($v->options, $v->templates, $data['quantity']);
        DB::transaction(function () use ($v, $data) {
            GuestSession::whereKey(session('guest_id'))->lockForUpdate()->firstOrFail();
            if (CartItem::where('guest_session_id', session('guest_id'))->count() >= 50 && ! CartItem::where('guest_session_id', session('guest_id'))->where('design_version_id', $v->id)->exists()) {
                throw ValidationException::withMessages(['cart' => 'الحد الأعلى 50 تصميماً في السلة.']);
            }
            $existing = CartItem::where('guest_session_id', session('guest_id'))->where('design_version_id', $v->id)->first();
            $previous = empty($data['cart_item_id']) ? null : CartItem::whereKey($data['cart_item_id'])->where('guest_session_id', session('guest_id'))->whereHas('version', fn ($q) => $q->where('design_id', $v->design_id))->firstOrFail();
            abort_if($previous && $existing && $previous->id !== $existing->id, 409, __('هذه النسخة موجودة في بند آخر بالسلة.'));
            $item = $previous ?? $existing ?? new CartItem(['guest_session_id' => session('guest_id')]);
            $item->design_version_id = $v->id;
            if (! $item->exists) {
                $item->id = (string) Str::uuid();
            }
            $item->fill(['product_id' => $v->options['product_id'] ?? null, 'quantity' => $data['quantity']])->save();
        }, 3);

        return response()->json(['redirect' => url('/cart')]);
    }

    public function updateCart(Request $r, CartItem $item, PricingService $pricing): RedirectResponse
    {
        abort_unless($item->guest_session_id === session('guest_id'), 404);
        $data = $r->validate(['quantity' => 'required|integer|between:1,100']);
        $pricing->quote($item->version->options, $item->version->templates, $data['quantity']);
        $item->update($data);

        return redirect('/cart')->with('success', 'تم تحديث الكمية.');
    }

    public function removeCart(CartItem $item): RedirectResponse
    {
        abort_unless($item->guest_session_id === session('guest_id'), 404);
        $item->delete();

        return redirect('/cart');
    }

    public function restore(Request $r, string $token): RedirectResponse
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $guest = GuestSession::where('recovery_hash', hash('sha256', $token))->firstOrFail();
        $r->session()->regenerate();
        session(['guest_id' => $guest->id, 'recovery_token' => $token]);
        $guest->update(['last_seen_at' => now()]);

        return redirect('/saved')->with('success', 'تمت استعادة تصاميمك. احتفظ بالرابط بشكل خاص.');
    }
}
