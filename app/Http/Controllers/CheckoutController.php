<?php

namespace App\Http\Controllers;

use App\Jobs\ExportOrderItem;
use App\Models\Asset;
use App\Models\Bundle;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Finish;
use App\Models\Governorate;
use App\Models\GuestSession;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DesignValidator;
use App\Services\Phone;
use App\Services\PricingService;
use App\Services\StoreSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public static function token(Order $order): string
    {
        return hash_hmac('sha256', $order->id, config('app.key'));
    }

    private function totals(PricingService $pricing, int $gov, ?int $area, ?string $coupon, bool $lock = false): array
    {
        $query = CartItem::with('version')->where('guest_session_id', session('guest_id'))->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }$items = $query->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'السلة فارغة.']);
        }$subtotal = 0;
        $quotes = [];
        foreach ($items as $i) {
            app(DesignValidator::class)->assertArtwork($i->version->document, $i->version->options);
            $q = $pricing->quote($i->version->options, $i->version->templates, $i->quantity);
            $quotes[$i->id] = $q;
            $subtotal += $q['subtotal_iqd'];
        } $rate = $pricing->shipping($gov, $area);
        $discount = $pricing->discount($coupon, $subtotal, $lock);
        $quantity = $items->sum('quantity');
        $bundle = Bundle::where('active', true)->where('minimum_quantity', '<=', $quantity)->orderByDesc('discount_percent')->first();
        $bundleAmount = $bundle ? intdiv($subtotal * $bundle->discount_percent, 100) : 0;
        $bundleName = null;
        if ($bundleAmount > $discount['amount_iqd']) {
            $discount = ['amount_iqd' => $bundleAmount, 'coupon' => null];
            $bundleName = $bundle->name;
        }

        return ['items' => $items, 'quotes' => $quotes, 'coupon' => $discount['coupon'], 'totals' => ['subtotal_iqd' => $subtotal, 'discount_iqd' => $discount['amount_iqd'], 'bundle' => $bundleName, 'shipping_iqd' => (int) $rate->fee_iqd, 'total_iqd' => $subtotal - $discount['amount_iqd'] + (int) $rate->fee_iqd, 'currency' => 'IQD', 'payment_method' => 'cod', 'estimated_time' => $rate->estimated_time, 'shipping_rate_id' => $rate->id]];
    }

    public function quote(Request $r, PricingService $pricing): JsonResponse
    {
        $d = $r->validate(['governorate_id' => 'required|integer|exists:governorates,id', 'area_id' => 'nullable|integer|exists:areas,id', 'coupon' => 'nullable|string|max:40']);

        return response()->json($this->totals($pricing, $d['governorate_id'], $d['area_id'] ?? null, $d['coupon'] ?? null)['totals']);
    }

    public function store(Request $r, PricingService $pricing): JsonResponse
    {
        $d = $r->validate(['request_id' => 'required|uuid', 'name' => 'required|string|min:2|max:100', 'phone' => 'required|string|max:30', 'alternate_phone' => 'nullable|string|max:30', 'governorate_id' => 'required|integer|exists:governorates,id', 'area_id' => 'nullable|integer|exists:areas,id', 'city' => 'required|string|max:100', 'district' => 'required|string|max:100', 'address' => 'required|string|min:5|max:500', 'landmark' => 'required|string|max:200', 'notes' => 'nullable|string|max:1000', 'coupon' => 'nullable|string|max:40', 'rights' => 'accepted', 'confirmed' => 'accepted']);
        $existing = Order::where('guest_session_id', session('guest_id'))->where('request_id', $d['request_id'])->first();
        if ($existing) {
            return $this->result($existing);
        }
        if (! StoreSettings::get('accepting_orders', false) || ! StoreSettings::get('cod_enabled', true)) {
            throw ValidationException::withMessages(['store' => 'استقبال الطلبات غير مفعّل حالياً. يمكنك حفظ تصميمك والعودة لاحقاً.']);
        }
        $d['phone'] = Phone::normalize($d['phone']);
        $d['alternate_phone'] = empty($d['alternate_phone']) ? null : Phone::normalize($d['alternate_phone']);
        $order = DB::transaction(function () use ($d, $pricing) {
            GuestSession::whereKey(session('guest_id'))->lockForUpdate()->firstOrFail();
            $existing = Order::where('guest_session_id', session('guest_id'))->where('request_id', $d['request_id'])->first();
            if ($existing) {
                return $existing;
            }$calc = $this->totals($pricing, $d['governorate_id'], $d['area_id'] ?? null, $d['coupon'] ?? null, true);
            $customer = Customer::create(['name' => $d['name'], 'phone' => $d['phone'], 'alternate_phone' => $d['alternate_phone'], 'user_id' => auth()->id()]);
            $id = (string) Str::uuid();
            $token = hash_hmac('sha256', $id, config('app.key'));
            $order = Order::create(['id' => $id, 'number' => 'SF-'.now()->format('ymd').'-'.strtoupper(Str::random(6)), 'tracking_hash' => hash('sha256', $token), 'guest_session_id' => session('guest_id'), 'customer_id' => $customer->id, 'request_id' => $d['request_id'], 'address' => collect($d)->only(['governorate_id', 'area_id', 'city', 'district', 'address', 'landmark'])->merge(['governorate' => Governorate::find($d['governorate_id'])->name])->all(), 'financial_status' => $calc['totals']['total_iqd'] === 0 ? 'paid' : 'unpaid', 'totals' => $calc['totals'], 'notes' => $d['notes'] ?? null]);
            foreach ($calc['items'] as $cart) {
                $v = $cart->version;
                $activeSides = $v->options['mode'] === 'single' ? [$v->options['printed_side']] : ['front', 'back'];
                $activeDocument = ['version' => 1, 'sides' => array_intersect_key($v->document['sides'], array_flip($activeSides))];
                $activeTemplates = array_intersect_key($v->templates, array_flip($activeSides));
                $ids = collect($activeDocument['sides'])->flatMap(fn ($s) => collect($s['elements'])->pluck('asset_id'))->filter()->unique();
                $assets = Asset::whereIn('id', $ids)->get()->mapWithKeys(fn ($a) => [$a->id => ['id' => $a->id, 'path' => $a->path, 'width' => $a->width, 'height' => $a->height, 'mime' => $a->mime, 'sha256' => $a->sha256]])->all();
                $disk = Storage::disk('local');
                foreach ($assets as $assetId => &$a) {
                    $frozenPath = 'order-assets/'.$order->id.'/'.$assetId.'.png';
                    if (! $disk->exists($frozenPath)) {
                        $disk->put($frozenPath, $disk->get($a['path']));
                    }$a['path'] = $frozenPath;
                }unset($a);
                $artAssets = [];
                foreach (collect($activeDocument['sides'])->flatMap(fn ($s) => collect($s['elements'])->pluck('art_key'))->filter()->unique() as $key) {
                    $bytes = file_get_contents(public_path('art/'.$key.'.svg'));
                    $frozenPath = 'order-assets/'.$order->id.'/'.$key.'.svg';
                    $disk->put($frozenPath, $bytes);
                    $artAssets[$key] = ['path' => $frozenPath, 'sha256' => hash('sha256', $bytes)];
                }$snapshot = ['art_assets' => $artAssets, 'name' => $v->design->name, 'document' => $activeDocument, 'options' => $v->options, 'templates' => $activeTemplates, 'assets' => $assets, 'materials' => Material::whereIn('id', array_filter([$v->options['material_id'] ?? null, $v->options['transparent_material_id'] ?? null]))->get()->toArray(), 'finish' => Finish::find($v->options['finish_id'] ?? null)?->toArray(), 'print_profile' => StoreSettings::get('print_profile'), 'design_revision' => $v->revision, 'rights_confirmed_at' => now()->toIso8601String()];
                $item = OrderItem::create(['order_id' => $order->id, 'design_version_id' => $v->id, 'snapshot' => $snapshot, 'quantity' => $cart->quantity, 'unit_price_iqd' => $calc['quotes'][$cart->id]['unit_iqd']]);
                ExportOrderItem::dispatch($item->id)->afterCommit();
            }
            if ($calc['coupon']) {
                $calc['coupon']->increment('used');
            }CartItem::whereIn('id', $calc['items']->pluck('id'))->delete();

            return $order;
        }, 3);

        return $this->result($order);
    }

    private function result(Order $order): JsonResponse
    {
        return response()->json(['redirect' => url('/orders/'.$order->id).'?token='.self::token($order), 'number' => $order->number]);
    }
}
