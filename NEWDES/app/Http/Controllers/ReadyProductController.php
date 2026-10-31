<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\NameFontPreview;
use App\Services\ReadyProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadyProductController extends Controller
{
    public function quote(Request $request, Product $product, ReadyProductService $service): JsonResponse
    {
        $configuration = $service->configuration($product, $request->all(), previewOnly: true);

        $document = $service->document($product, $configuration);

        $previews = app(NameFontPreview::class);
        $images = $previews->images($document);
        $nameBounds = $configuration['options']['personalization']['bounds'] ?? null;
        if ($nameBounds === null && $images !== []) {
            $element = collect($document['sides'])->flatMap(fn (array $side) => $side['elements'])->first(fn (array $element) => $element['type'] === 'text');
            $nameBounds = $element ? $previews->bounds($element) : null;
        }

        return response()->json([...$configuration['price'], 'preview' => ['document' => $document, 'textImages' => $images, 'nameBounds' => $nameBounds, 'templates' => $configuration['templates'], 'options' => $configuration['options']]])->header('Cache-Control', 'private, no-store');
    }

    public function cart(Request $request, Product $product, ReadyProductService $service): JsonResponse
    {
        $item = $service->addToCart($product, $request->all());

        return response()->json(['redirect' => url('/cart'), 'item_id' => $item->id]);
    }
}
