<?php

namespace App\Http\Controllers;

use App\Models\CostRecipe;
use App\Models\CostRun;
use App\Models\StockItem;
use App\Services\InventoryService;
use App\Services\StickerCostService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CostingController extends Controller
{
    private function admin(): void
    {
        abort_unless(auth()->user()?->role === 'admin', 403);
    }

    private function page(array $parameters = [], ?array $result = null, ?CostRecipe $recipe = null): View
    {
        return view('admin.accounting-costing', ['items' => StockItem::where('active', true)->orderBy('name')->get(), 'recipes' => CostRecipe::orderBy('name')->get(), 'recipe' => $recipe, 'parameters' => $parameters, 'result' => $result]);
    }

    public function index(Request $request): View
    {
        $this->admin();
        $data = $request->validate(['recipe' => 'nullable|integer|exists:cost_recipes,id']);
        $recipe = empty($data['recipe']) ? null : CostRecipe::findOrFail($data['recipe']);

        return $this->page($recipe?->parameters ?? [], recipe: $recipe);
    }

    public function calculate(Request $request, StickerCostService $costs): View|JsonResponse
    {
        $this->admin();
        $result = $costs->estimate($request->all());
        if ($request->expectsJson()) {
            return response()->json(['html' => view('admin.accounting-result', ['result' => $result])->render(), 'stock_sufficient' => $result['stock_sufficient']]);
        }
        $recipe = $request->filled('recipe_id') ? CostRecipe::find($request->input('recipe_id')) : null;

        return $this->page($result['parameters'], $result, $recipe);
    }

    public function saveRecipe(Request $request, StickerCostService $costs, InventoryService $inventory): RedirectResponse
    {
        $this->admin();
        $data = $request->validate(['recipe_name' => 'required|string|max:120', 'recipe_id' => 'nullable|integer|exists:cost_recipes,id']);
        $parameters = $costs->parameters($request->all());
        unset($parameters['quantity'], $parameters['actual_sheets'], $parameters['sale_price_iqd']);
        $recipe = empty($data['recipe_id']) ? new CostRecipe : CostRecipe::findOrFail($data['recipe_id']);
        $recipe->fill(['name' => $data['recipe_name'], 'parameters' => $parameters, 'user_id' => auth()->id()])->save();
        $inventory->audit('cost.recipe_saved', 'CostRecipe', $recipe->id);

        return redirect()->route('accounting.costing', ['recipe' => $recipe->id])->with('success', 'تم حفظ إعداد الكلفة؛ الحسابات التالية تستخدم متوسط كلفة المخزن وقت الحساب.');
    }

    public function produce(Request $request, StickerCostService $costs): RedirectResponse
    {
        $this->admin();
        $run = $costs->produce($request->all());

        return redirect()->route('accounting.production')->with('success', 'تم تسجيل '.$run->number.' وتثبيت الكلفة وخصم المواد مرة واحدة.');
    }

    public function production(Request $request): View
    {
        $this->admin();
        $runs = CostRun::latest();
        if ($request->filled('q')) {
            $search = preg_replace('/^PR-/i', '', (string) $request->string('q')->limit(100));
            $runs->where(fn ($query) => $query->where('reference', 'like', '%'.$search.'%')->orWhere('id', 'like', '%'.$search.'%'));
        }

        return view('admin.accounting-production', ['runs' => $runs->paginate(12)->withQueryString()]);
    }

    public function voidRun(Request $request, CostRun $run, StickerCostService $costs): RedirectResponse
    {
        $this->admin();
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $costs->voidRun($run, $data['reason']);

        return back()->with('success', 'تم إلغاء تسجيل الدفعة وإرجاع المواد بكلفتها الأصلية.');
    }
}
