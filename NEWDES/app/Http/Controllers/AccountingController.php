<?php

namespace App\Http\Controllers;

use App\Models\AccountingEntry;
use App\Models\AccountingPayment;
use App\Models\Collection;
use App\Models\CostRun;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\CostingMath;
use App\Services\InventoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountingController extends Controller
{
    private function admin(): void
    {
        abort_unless(auth()->user()?->role === 'admin', 403);
    }

    public function index(): View
    {
        $this->admin();
        $paid = AccountingPayment::join('accounting_entries', 'accounting_entries.id', '=', 'accounting_payments.accounting_entry_id')->whereNull('accounting_entries.voided_at')->select('accounting_entries.type', DB::raw('SUM(accounting_payments.amount_iqd) AS total'))->groupBy('accounting_entries.type')->pluck('total', 'type')->map(fn ($amount): int => (int) $amount)->all();
        $customerReceipts = (int) Collection::where('method', 'cod_manual')->sum('amount_iqd') - (int) Collection::where('method', 'refund')->sum('amount_iqd');
        $cash = $customerReceipts + ($paid['capital_in'] ?? 0) - array_sum(array_intersect_key($paid, array_flip(['capital_out', 'material_purchase', 'equipment_purchase', 'expense'])));

        return view('admin.accounting', ['paid' => $paid, 'cash' => $cash, 'customerReceipts' => $customerReceipts, 'stockValue' => (int) StockItem::sum('value_milli_iqd'), 'equipment' => (int) AccountingEntry::whereNull('voided_at')->where('type', 'equipment_purchase')->sum('amount_iqd'), 'remaining' => (int) AccountingEntry::whereNull('voided_at')->sum(DB::raw('amount_iqd - paid_iqd')), 'lowStock' => StockItem::where('active', true)->whereColumn('quantity_milli', '<=', 'minimum_milli')->get(), 'entries' => AccountingEntry::latest()->limit(8)->get(), 'runs' => CostRun::whereNull('voided_at')->count()]);
    }

    public function entries(Request $request): View
    {
        $this->admin();
        $input = $request->validate(['q' => 'nullable|string|max:100', 'type' => ['nullable', Rule::in(array_keys(AccountingEntry::TYPES))]]);
        $query = AccountingEntry::with('stockItem', 'payments')->latest();
        if (! empty($input['q'])) {
            $search = str_starts_with(strtoupper($input['q']), 'AC-') ? substr($input['q'], 3) : $input['q'];
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('supplier', 'like', '%'.$search.'%')->orWhere('id', 'like', '%'.$search.'%'));
        }
        if (! empty($input['type'])) {
            $query->where('type', $input['type']);
        }

        return view('admin.accounting-entries', ['entries' => $query->paginate(15)->withQueryString(), 'items' => StockItem::where('active', true)->orderBy('name')->get()]);
    }

    public function postEntry(Request $request, InventoryService $inventory): RedirectResponse
    {
        $this->admin();
        $entry = $inventory->post($request->all());

        return redirect()->route('accounting.entries')->with('success', 'تم تسجيل '.$entry->number.' وحفظ أثره على المخزن والمدفوعات.');
    }

    public function payment(Request $request, AccountingEntry $entry, InventoryService $inventory): RedirectResponse
    {
        $this->admin();
        $inventory->pay($entry, $request->all());

        return back()->with('success', 'تم تسجيل الدفعة.');
    }

    public function voidEntry(Request $request, AccountingEntry $entry, InventoryService $inventory): RedirectResponse
    {
        $this->admin();
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $inventory->voidEntry($entry, $data['reason']);

        return back()->with('success', 'تم إلغاء تسجيل القيد مع حفظ السجل وعكس حركة المخزن المرتبطة إن وجدت.');
    }

    public function stock(Request $request, ?StockItem $item = null): View
    {
        $this->admin();
        $query = StockItem::orderBy('name');
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q')->limit(100).'%');
        }
        $movements = StockMovement::with('item')->when($item, fn ($query) => $query->where('stock_item_id', $item->id))->latest('id')->paginate(20, ['*'], 'movements_page')->withQueryString();

        return view('admin.accounting-stock', ['items' => $query->paginate(15)->withQueryString(), 'item' => $item, 'movements' => $movements]);
    }

    public function saveStock(Request $request, InventoryService $inventory, ?StockItem $item = null): RedirectResponse
    {
        $this->admin();
        $data = $request->validate([
            'name' => 'required|string|max:120', 'code' => ['required', 'alpha_dash', 'max:60', Rule::unique('stock_items', 'code')->ignore($item?->id)],
            'unit' => ['required', Rule::in(array_keys(StockItem::UNITS))],
            'width_mm' => 'nullable|required_if:unit,sheet|numeric|between:10,3000|decimal:0,2',
            'height_mm' => 'nullable|required_if:unit,sheet|numeric|between:10,3000|decimal:0,2',
            'minimum' => 'nullable|numeric|between:0,1000000|decimal:0,3', 'active' => 'sometimes|boolean',
        ]);
        $payload = collect($data)->only(['name', 'code', 'unit'])->all() + ['width_mm' => $data['unit'] === 'sheet' ? $data['width_mm'] : null, 'height_mm' => $data['unit'] === 'sheet' ? $data['height_mm'] : null, 'minimum_milli' => CostingMath::scaled((string) ($data['minimum'] ?? '0')), 'active' => $request->boolean('active')];
        $record = DB::transaction(function () use ($item, $payload, $inventory): StockItem {
            $record = $item ? StockItem::whereKey($item->id)->lockForUpdate()->firstOrFail() : new StockItem;
            if ($record->exists && $record->movements()->exists()) {
                foreach (['unit', 'width_mm', 'height_mm'] as $key) {
                    $changed = $key === 'unit' ? $payload[$key] !== $record->$key : CostingMath::scaled((string) ($record->$key ?? '0'), 2) !== CostingMath::scaled((string) ($payload[$key] ?? '0'), 2);
                    if ($changed) {
                        throw ValidationException::withMessages(['unit' => 'وحدة وأبعاد مادة لها حركات محفوظة ثابتة. أنشئ مادة مستقلة للقياس أو الوحدة الجديدة.']);
                    }
                }
            }
            $record->fill($payload)->save();
            $inventory->audit('stock.item_saved', 'StockItem', $record->id, $payload);

            return $record;
        });

        return redirect()->route('accounting.stock.item', $record)->with('success', 'تم حفظ مادة المخزن. الرصيد يتغير من المشتريات والاستهلاك المسجل.');
    }

    public function waste(Request $request, StockItem $item, InventoryService $inventory): RedirectResponse
    {
        $this->admin();
        $inventory->waste($item, $request->all());

        return back()->with('success', 'تم تسجيل الاستهلاك اليدوي / التالف.');
    }

    public function reverseMovement(Request $request, StockMovement $movement, InventoryService $inventory): RedirectResponse
    {
        $this->admin();
        abort_unless($movement->type === 'waste', 422);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);
        DB::transaction(function () use ($movement, $data, $inventory): void {
            $inventory->reverse($movement);
            $inventory->audit('stock.waste_reversed', 'StockMovement', $movement->id, $data);
        });

        return back()->with('success', 'تم عكس حركة التالف.');
    }
}
