<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\CartItem;
use App\Models\CatalogAsset;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Design;
use App\Models\Finish;
use App\Models\Governorate;
use App\Models\Material;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReviewRequest;
use App\Models\TemplateVersion;
use App\Services\CatalogService;
use App\Services\DesignValidator;
use App\Services\Ownership;
use App\Services\Phone;
use App\Services\PricingService;
use App\Services\ReadyProductService;
use App\Services\StoreSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StoreController extends Controller
{
    public function home(): View
    {
        $best = Product::bestsellers()->orderByDesc('popularity_count')->orderBy('id')->limit(4)->get();

        return view('store.home', ['products' => Product::where('active', true)->latest()->limit(4)->get(), 'categories' => Category::where('active', true)->get(), 'best' => $best]);
    }

    public function products(Request $r): View
    {
        $q = Product::with('category')->where('active', true);
        $generalPrice = StoreSettings::get('price_single_iqd');
        if ($r->filled('q')) {
            $q->where(fn ($b) => $b->where('name', 'like', '%'.$r->string('q')->limit(100).'%')->orWhere('description', 'like', '%'.$r->string('q')->limit(100).'%'));
        }if ($r->filled('category')) {
            $q->whereHas('category', fn ($b) => $b->where('slug', $r->input('category')));
        }if ($r->filled('available') && $generalPrice === null) {
            $q->whereNotNull('price_iqd');
        }
        if ($r->boolean('bestsellers')) {
            $q->bestsellers();
        }
        $sort = $r->input('sort', $r->boolean('bestsellers') ? 'popular' : 'latest');
        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $direction = $sort === 'price_asc' ? 'ASC' : 'DESC';
            $q->orderByRaw('COALESCE(price_iqd, ?) IS NULL', [$generalPrice])
                ->orderByRaw('COALESCE(price_iqd, ?) '.$direction, [$generalPrice])->orderBy('id');
        } else {
            match ($sort) {
                'popular' => $q->orderByDesc('popularity_count')->orderBy('id'),
                'name' => $q->orderBy('name'), default => $q->latest()
            };
        }

        return view('store.products', ['products' => $q->paginate(12)->withQueryString(), 'categories' => Category::where('active', true)->get()]);
    }

    public function product(Request $request, Product $product, ReadyProductService $service): View
    {
        abort_unless($product->active, 404);
        $cartItem = $request->filled('cart') ? CartItem::with('version')->whereKey($request->input('cart'))->where('guest_session_id', session('guest_id'))->where('product_id', $product->id)->firstOrFail() : null;

        return view('store.product', [...$service->choices(), 'product' => $product, 'cartItem' => $cartItem]);
    }

    public function editor(Request $r, ?Design $design = null): View|RedirectResponse
    {
        if ($design) {
            Ownership::check($design);
        }
        $readyId = $design?->options['product_id'] ?? $r->input('product');
        if ($readyId !== null) {
            $product = Product::where('active', true)->find($readyId);

            return $product ? redirect()->route('products.show', ['product' => $product->slug]) : redirect('/designs');
        }
        $product = null;

        return view('store.editor', ['design' => $design, 'product' => $product, 'productAsset' => $product?->catalogAsset ? app(CatalogService::class)->guestAsset($product->catalogAsset) : null, 'templates' => TemplateVersion::with('template')->orderByDesc('version')->get()->map(fn ($v) => ['id' => $v->id, 'name' => $v->template->name, 'side' => $v->template->side, 'approved' => (bool) $v->approved_at && $v->template->active, 'version' => $v->version, 'geometry' => $v->geometry, 'compatibility' => $v->compatibility]), 'materials' => Material::where('active', true)->get(), 'finishes' => Finish::where('active', true)->get()]);
    }

    public function saved(): View
    {
        return view('store.saved', ['designs' => Design::where(fn ($q) => $q->where('guest_session_id', session('guest_id'))->when(auth()->check(), fn ($b) => $b->orWhere('user_id', auth()->id())))->whereNull('options->product_id')->latest('updated_at')->paginate(12)]);
    }

    public function cart(PricingService $pricing): View
    {
        $items = CartItem::with('version', 'product')->where('guest_session_id', session('guest_id'))->get();
        $total = 0;
        $availabilityErrors = [];
        foreach ($items as $item) {
            try {
                app(DesignValidator::class)->assertArtwork($item->version->document, $item->version->options);
                $quote = $pricing->quote($item->version->options, $item->version->templates, $item->quantity);
                $item->setAttribute('quote', $quote);
                $total += $quote['subtotal_iqd'];
            } catch (ValidationException $e) {
                $availabilityErrors[$item->id] = collect($e->errors())->flatten()->first();
            }
        }

        return view('store.cart', compact('items', 'total', 'availabilityErrors'));
    }

    public function checkout(): View|RedirectResponse
    {
        if (! CartItem::where('guest_session_id', session('guest_id'))->exists()) {
            return redirect('/cart');
        }

        return view('store.checkout', ['governorates' => Governorate::where('active', true)->get()]);
    }

    public function order(Request $r, Order $order): View
    {
        $token = $r->query('token', '');
        abort_unless(hash_equals($order->tracking_hash, hash('sha256', $token)), 404);
        $order->load('items');

        return view('store.order', compact('order', 'token'));
    }

    public function orderAsset(Request $r, Order $order, string $asset): BinaryFileResponse
    {
        abort_unless(hash_equals($order->tracking_hash, hash('sha256', $r->query('token', ''))), 404);
        foreach ($order->items as $item) {
            $a = $item->snapshot['assets'][$asset] ?? null;
            if ($a) {
                return response()->file(Storage::disk('local')->path($a['path']), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
            }
        }abort(404);
    }

    public function orderArt(Request $r, Order $order, string $key): BinaryFileResponse
    {
        abort_unless(hash_equals($order->tracking_hash, hash('sha256', $r->query('token', ''))), 404);
        foreach ($order->items as $item) {
            $a = $item->snapshot['art_assets'][$key] ?? null;
            if ($a) {
                return response()->file(Storage::disk('local')->path($a['path']), ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store']);
            }
        }abort(404);
    }

    public function track(Request $r): View
    {
        return view('store.track');
    }

    public function guide(): View
    {
        return view('store.guide', ['templates' => TemplateVersion::with('template')->get()]);
    }

    public function page(string $slug): View
    {
        if (in_array($slug, ['installation', 'faq', 'contact'])) {
            return view('store.'.$slug);
        }$page = ContentPage::where('slug', $slug)->where('published', true)->firstOrFail();

        return view('store.page', compact('page'));
    }

    public function review(Request $r): RedirectResponse
    {
        $data = $r->validate(['description' => 'required|string|min:10|max:2000', 'phone' => 'nullable|string|max:30']);
        ReviewRequest::create(['guest_session_id' => session('guest_id'), 'description' => $data['description'], 'phone' => empty($data['phone']) ? null : Phone::normalize($data['phone'])]);

        return back()->with('success', 'وصل طلب المراجعة. لا ترسل صورة بطاقتك أو معلوماتها المصرفية.');
    }

    public function catalog(Request $request, CatalogAsset $asset, CatalogService $catalog): BinaryFileResponse
    {
        abort_unless(Product::where('active', true)->where('catalog_asset_id', $asset->id)->exists(), 404);

        $path = $request->boolean('preview') ? $catalog->previewPath($asset) : $asset->thumbnail_path;

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function media(Asset $asset): BinaryFileResponse
    {
        Ownership::check($asset);

        return response()->file(Storage::disk('local')->path($asset->path), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    public function sitemap(): Response
    {
        $urls = ['/', '/designs', '/guide', '/installation', '/faq', '/contact'];
        foreach (Product::where('active', true)->get() as $product) {
            $urls[] = '/products/'.$product->slug;
        }

        return response()->view('sitemap', compact('urls'))->header('Content-Type', 'application/xml');
    }
}
