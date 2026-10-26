<?php

namespace App\Http\Controllers;

use App\Models\ProductSection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(): View
    {
        return view('store.devices', ['sections' => ProductSection::where('active', true)->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function section(Request $request, ProductSection $section): View
    {
        abort_unless($section->active, 404);
        $input = $request->validate(['q' => 'nullable|string|max:100', 'sort' => 'nullable|in:latest,name,featured']);
        $products = $section->products()->where('active', true);
        if (! empty($input['q'])) {
            $products->where(fn ($query) => $query->where('name', 'like', '%'.$input['q'].'%')->orWhere('description', 'like', '%'.$input['q'].'%'));
        }
        match ($input['sort'] ?? 'latest') {
            'name' => $products->orderBy('name'),
            'featured' => $products->orderByDesc('is_featured')->latest(),
            default => $products->latest(),
        };

        return view('store.device-section', ['section' => $section, 'products' => $products->paginate(12)->withQueryString()]);
    }
}
