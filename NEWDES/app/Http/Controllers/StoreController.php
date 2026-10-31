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
use App\Models\ProductSection;
use App\Models\ReviewRequest;
use App\Models\TemplateVersion;
use App\Services\CatalogService;
use App\Services\DesignValidator;
use App\Services\Ownership;
use App\Services\Phone;
use App\Services\PricingService;
use App\Services\ReadyProductService;
use App\Services\StoreSettings;
use App\Services\TemplateCatalog;
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
        $best = Product::cardSkins()->bestsellers()->orderByDesc('popularity_count')->orderBy('id')->limit(4)->get();
        $products = Product::cardSkins()->with('category')->where('active', true)->where('is_featured', true)->orderBy('featured_order')->orderBy('id')->limit(8)->get();
        if ($products->count() < 8) {
            $products = $products->concat(Product::cardSkins()->with('category')->where('active', true)->whereNotIn('id', $products->modelKeys())->latest()->latest('id')->limit(8 - $products->count())->get());
        }

        return view('store.home', ['personalizedProducts' => Product::cardSkins()->with('category')->where('active', true)->where('personalization_enabled', true)->featuredFirst()->limit(4)->get(), 'products' => $products, 'categories' => Category::where('active', true)->get(), 'best' => $best, 'deviceSections' => ProductSection::where('active', true)->where('audience', 'devices')->whereNull('parent_id')->orderBy('sort_order')->get()]);
    }

    public function products(Request $r): View
    {
        $input = $r->validate([
            'q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100',
            'sort' => 'nullable|in:recommended,latest,name,popular,price_asc,price_desc',
            'template' => 'nullable|in:small_chip,no_chip', 'material' => 'nullable|integer',
            'min_price' => 'nullable|integer|min:0|max:100000000', 'max_price' => 'nullable|integer|min:0|max:100000000',
        ]);
        if ($r->filled('min_price') && $r->filled('max_price') && $input['max_price'] < $input['min_price']) {
            throw ValidationException::withMessages(['max_price' => __('الحد الأعلى للسعر يجب أن يكون أكبر من الحد الأدنى.')]);
        }
        $q = Product::cardSkins()->with('category')->where('active', true);
        if ($r->boolean('personalized')) {
            $q->where('personalization_enabled', true);
        }
        $generalPrice = StoreSettings::get('price_single_iqd');
        if ($r->filled('q')) {
            $q->where(fn ($b) => $b->where('name', 'like', '%'.$input['q'].'%')->orWhere('description', 'like', '%'.$input['q'].'%'));
        }
        if ($r->filled('category')) {
            $q->where(fn ($c) => $c->whereHas('category', fn ($b) => $b->where('slug', $input['category']))->orWhereHas('categories', fn ($b) => $b->where('slug', $input['category'])));
        }
        if ($r->boolean('available') && $generalPrice === null) {
            $q->whereNotNull('price_iqd');
        }
        if ($r->boolean('bestsellers')) {
            $q->bestsellers();
        }
        $choices = TemplateCatalog::choices()->filter(fn (array $t): bool => $t['approved'] && $t['side'] === 'front');
        $matching = $choices->filter(fn (array $t): bool => (! $r->filled('template') || $t['kind'] === $input['template']) && (! $r->filled('material') || in_array((int) $input['material'], $t['compatibility']['material_ids'] ?? [], true)));
        if (($r->filled('template') || $r->filled('material')) && $matching->isEmpty()) {
            $q->whereRaw('1 = 0');
        }
        if ($r->filled('material') && ! Material::whereKey($input['material'])->where('active', true)->where('transparent', false)->exists()) {
            $q->whereRaw('1 = 0');
        }
        foreach (['min_price' => '>=', 'max_price' => '<='] as $field => $operator) {
            if ($r->filled($field)) {
                $q->whereRaw('COALESCE(price_iqd, ?) '.$operator.' ?', [$generalPrice, (int) $input[$field]]);
            }
        }
        $sort = $input['sort'] ?? ($r->boolean('bestsellers') ? 'popular' : 'recommended');
        match ($sort) {
            'price_asc', 'price_desc' => $q->orderByRaw('COALESCE(price_iqd, ?) IS NULL', [$generalPrice])->orderByRaw('COALESCE(price_iqd, ?) '.($sort === 'price_asc' ? 'ASC' : 'DESC'), [$generalPrice])->orderBy('id'),
            'popular' => $q->orderByDesc('popularity_count')->orderBy('id'),
            'name' => $q->orderBy('name')->orderBy('id'),
            'latest' => $q->latest()->orderByDesc('id'),
            default => $q->featuredFirst()->latest()->orderByDesc('id'),
        };

        return view('store.products', ['products' => $q->paginate(12)->withQueryString(), 'categories' => Category::where('active', true)->get(), 'materials' => Material::where('active', true)->where('transparent', false)->get()]);
    }

    public function product(Request $request, Product $product, ReadyProductService $service): View
    {
        abort_unless($product->active, 404);
        if ($product->product_section_id !== null) {
            abort_unless($product->section?->active, 404);

            return view('store.device-product', ['product' => $product, 'section' => $product->section]);
        }
        $cartItem = $request->filled('cart') ? CartItem::with('version')->whereKey($request->input('cart'))->where('guest_session_id', session('guest_id'))->where('product_id', $product->id)->firstOrFail() : null;

        return view('store.product', [...$service->choices(), 'product' => $product, 'cartItem' => $cartItem, 'related' => Product::cardSkins()->where('active', true)->whereKeyNot($product->id)->where('category_id', $product->category_id)->featuredFirst()->limit(4)->get()]);
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

        return view('store.editor', ['cartItem' => $r->filled('cart') && $design ? CartItem::whereKey($r->input('cart'))->where('guest_session_id', session('guest_id'))->whereHas('version', fn ($q) => $q->where('design_id', $design->id))->firstOrFail() : null, 'design' => $design, 'product' => $product, 'productAsset' => $product?->catalogAsset ? app(CatalogService::class)->guestAsset($product->catalogAsset) : null, 'templates' => TemplateCatalog::choices(), 'historicalTemplates' => TemplateVersion::with('template')->whereIn('id', collect($design?->document['sides'] ?? [])->pluck('template_version_id')->filter())->get()->map(fn (TemplateVersion $version): array => TemplateCatalog::describe($version)), 'materials' => Material::where('active', true)->get(), 'finishes' => Finish::where('active', true)->get()]);
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
        $items = CartItem::with('version.design')->where('guest_session_id', session('guest_id'))->get();
        if ($items->isEmpty()) {
            return redirect('/cart');
        }

        $customItems = $items->filter(fn (CartItem $item): bool => $item->product_id === null && empty($item->version->options['product_id']) && empty($item->version->options['ready_locked']));

        return view('store.checkout', ['governorates' => Governorate::where('active', true)->get(), 'items' => $items, 'customItems' => $customItems]);
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
        return view('store.guide', ['templates' => TemplateCatalog::versions()]);
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
        abort_unless(Product::publiclyVisible()->where('catalog_asset_id', $asset->id)->exists(), 404);

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
        $urls = ['/', '/designs', '/devices', '/wholesale', '/guide', '/installation', '/faq', '/contact'];
        foreach (ProductSection::where('active', true)->get() as $section) {
            $urls[] = '/devices/'.$section->slug;
        }
        foreach (Product::publiclyVisible()->get() as $product) {
            $urls[] = '/products/'.$product->slug;
        }

        return response()->view('sitemap', compact('urls'))->header('Content-Type', 'application/xml');
    }
}
