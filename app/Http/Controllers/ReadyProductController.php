<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ReadyProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadyProductController extends Controller
{
    public function quote(Request $request, Product $product, ReadyProductService $service): JsonResponse
    {
        $configuration = $service->configuration($product, $request->all());

        return response()->json([...$configuration['price'], 'preview' => ['document' => $service->document($product, $configuration), 'templates' => $configuration['templates'], 'options' => $configuration['options']]]);
    }

    public function cart(Request $request, Product $product, ReadyProductService $service): JsonResponse
    {
        $item = $service->addToCart($product, $request->all());

        return response()->json(['redirect' => url('/cart'), 'item_id' => $item->id]);
    }
}
