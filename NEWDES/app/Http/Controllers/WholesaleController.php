<?php

namespace App\Http\Controllers;

use App\Models\ProductSection;
use App\Models\WholesaleTier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WholesaleController extends Controller
{
    public function index(): View
    {
        return view('store.wholesale', ['sections' => ProductSection::where('active', true)->where('audience', 'business')->orderBy('sort_order')->get(), 'tiers' => WholesaleTier::with('section', 'material')->where('active', true)->whereHas('section', fn ($q) => $q->where('active', true))->orderBy('minimum_quantity')->get()]);
    }

    public function store(Request $request, CatalogRequestController $controller): RedirectResponse
    {
        $data = $request->validate(['section_id' => 'required|integer']);
        $section = ProductSection::where('active', true)->where('audience', 'business')->findOrFail($data['section_id']);

        return $controller->store($request, $section);
    }
}
