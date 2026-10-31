<?php

namespace App\Services;

use App\Models\CostRun;
use App\Models\StockItem;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StickerCostService
{
    public function __construct(private InventoryService $inventory) {}

    public function parameters(array $input): array
    {
        $data = Validator::make($input, [
            'paper_id' => 'required|integer|exists:stock_items,id', 'width_mm' => 'required|numeric|between:1,3000|decimal:0,2', 'height_mm' => 'required|numeric|between:1,3000|decimal:0,2',
            'pieces_per_sheet' => 'required|integer|between:1,10000', 'print_layers' => 'required|integer|in:1,2', 'quantity' => 'required|integer|between:1,100000',
            'bleed_mm' => 'required|numeric|between:0,20|decimal:0,2', 'margin_mm' => 'required|numeric|between:0,100|decimal:0,2', 'gap_mm' => 'required|numeric|between:0,100|decimal:0,2',
            'waste_percent' => 'required|numeric|between:0,50|decimal:0,2', 'other_per_sheet_iqd' => 'required|integer|between:0,1000000000', 'other_per_piece_iqd' => 'required|integer|between:0,1000000000',
            'sale_price_iqd' => 'nullable|integer|between:0,1000000000', 'actual_sheets' => 'nullable|integer|between:1,400000',
            'components' => 'nullable|array|max:8', 'components.*.stock_item_id' => 'nullable|integer|exists:stock_items,id', 'components.*.quantity' => 'nullable|numeric|between:0,1000|decimal:0,3', 'components.*.basis' => 'nullable|in:per_sheet,per_piece',
        ])->validate();
        $out = [];
        foreach (['paper_id', 'pieces_per_sheet', 'print_layers', 'quantity', 'other_per_sheet_iqd', 'other_per_piece_iqd'] as $key) {
            $out[$key] = (int) $data[$key];
        }
        foreach (['width_mm', 'height_mm', 'bleed_mm', 'margin_mm', 'gap_mm', 'waste_percent'] as $key) {
            $out[$key] = (string) $data[$key];
        }
        $out['sale_price_iqd'] = isset($data['sale_price_iqd']) ? (int) $data['sale_price_iqd'] : null;
        $out['actual_sheets'] = isset($data['actual_sheets']) ? (int) $data['actual_sheets'] : null;
        $out['components'] = [];
        $used = [$out['paper_id']];
        foreach ($data['components'] ?? [] as $component) {
            $id = $component['stock_item_id'] ?? null;
            $quantity = CostingMath::scaled((string) ($component['quantity'] ?? '0'));
            if (! $id && $quantity === 0) {
                continue;
            }
            if (! $id || $quantity === 0 || in_array((int) $id, $used, true)) {
                throw ValidationException::withMessages(['components' => 'اختر مواد مختلفة وكميات استهلاك أكبر من صفر. لا تكرر ورق الطباعة ضمن الإضافات.']);
            }
            $used[] = (int) $id;
            $out['components'][] = ['stock_item_id' => (int) $id, 'quantity' => CostingMath::format($quantity, false), 'basis' => $component['basis'] ?? 'per_sheet'];
        }

        return $out;
    }

    public function estimate(array $input): array
    {
        $parameters = $this->parameters($input);
        $items = StockItem::whereIn('id', $this->itemIds($parameters))->get()->keyBy('id');

        return $this->calculate($parameters, $items);
    }

    private function itemIds(array $parameters): array
    {
        $ids = [$parameters['paper_id'], ...array_column($parameters['components'], 'stock_item_id')];
        sort($ids);

        return $ids;
    }

    /** @param Collection<int, StockItem> $items */
    private function calculate(array $parameters, Collection $items): array
    {
        $paper = $items->get($parameters['paper_id']);
        if (! $paper?->active || $paper->unit !== 'sheet' || ! $paper->width_mm || ! $paper->height_mm) {
            throw ValidationException::withMessages(['paper_id' => 'اختر ورقاً نشطاً بوحدة ورقة، وحدد أبعاده في المخزن.']);
        }
        $margin = CostingMath::scaled($parameters['margin_mm'], 2);
        $usableWidth = CostingMath::scaled($paper->width_mm, 2) - 2 * $margin;
        $usableHeight = CostingMath::scaled($paper->height_mm, 2) - 2 * $margin;
        $width = CostingMath::scaled($parameters['width_mm'], 2) + 2 * CostingMath::scaled($parameters['bleed_mm'], 2);
        $height = CostingMath::scaled($parameters['height_mm'], 2) + 2 * CostingMath::scaled($parameters['bleed_mm'], 2);
        $gap = CostingMath::scaled($parameters['gap_mm'], 2);
        $fit = static fn (int $w, int $h): int => intdiv(max(0, $usableWidth + $gap), $w + $gap) * intdiv(max(0, $usableHeight + $gap), $h + $gap);
        $capacity = max($fit($width, $height), $fit($height, $width));
        if ($usableWidth <= 0 || $usableHeight <= 0 || $capacity === 0) {
            throw ValidationException::withMessages(['width_mm' => 'القياس مع النزف والهوامش لا يتسع لقطعة واحدة على الورقة.']);
        }
        $areaLimit = intdiv($usableWidth * $usableHeight, $width * $height);
        if ($parameters['pieces_per_sheet'] > $areaLimit) {
            throw ValidationException::withMessages(['pieces_per_sheet' => 'عدد القطع المدخل يتجاوز مساحة الورقة المتاحة لهذا القياس.']);
        }
        $minimumSheets = CostingMath::mulDiv($parameters['quantity'], 1, $parameters['pieces_per_sheet'], RoundingMode::Ceiling) * $parameters['print_layers'];
        $waste = CostingMath::scaled($parameters['waste_percent'], 2);
        $layouts = CostingMath::mulDiv($parameters['quantity'], 10000, $parameters['pieces_per_sheet'] * (10000 - $waste), RoundingMode::Ceiling);
        $sheets = $parameters['actual_sheets'] ?? $layouts * $parameters['print_layers'];
        if ($sheets < $minimumSheets) {
            throw ValidationException::withMessages(['actual_sheets' => 'الأوراق المصروفة أقل من الحد اللازم لإنتاج الكمية المدخلة.']);
        }
        $requirements = [['stock_item_id' => $paper->id, 'quantity_milli' => $sheets * 1000, 'basis' => 'paper']];
        foreach ($parameters['components'] as $component) {
            $requirements[] = ['stock_item_id' => $component['stock_item_id'], 'quantity_milli' => CostingMath::scaled($component['quantity']) * ($component['basis'] === 'per_sheet' ? $sheets : $parameters['quantity']), 'basis' => $component['basis']];
        }
        $rows = [];
        $materials = 0;
        foreach ($requirements as $requirement) {
            $item = $items->get($requirement['stock_item_id']);
            if (! $item?->active || $item->valuation_quantity_milli <= 0) {
                throw ValidationException::withMessages(['cost' => 'سجّل شراء «'.($item?->name ?? 'المادة').'» وكلفتها أولاً.']);
            }
            $quantity = $requirement['quantity_milli'];
            $value = $item->quantity_milli > 0 ? $item->value_milli_iqd : $item->valuation_value_milli_iqd;
            $basis = $item->quantity_milli > 0 ? $item->quantity_milli : $item->valuation_quantity_milli;
            $cost = CostingMath::mulDiv($value, $quantity, $basis);
            $materials += $cost;
            $rows[] = $requirement + ['name' => $item->name, 'unit' => $item->unit, 'cost_milli_iqd' => $cost, 'available_milli' => $item->quantity_milli, 'sufficient' => $quantity <= $item->quantity_milli, 'unit_cost_milli_iqd' => CostingMath::mulDiv($value, 1000, $basis)];
        }
        $other = $parameters['other_per_sheet_iqd'] * $sheets * 1000 + $parameters['other_per_piece_iqd'] * $parameters['quantity'] * 1000;
        $total = $materials + $other;
        if ($total > 9000000000000000) {
            throw ValidationException::withMessages(['cost' => 'الكلفة تتجاوز مجال الحساب. راجع الأرقام.']);
        }

        return ['parameters' => $parameters, 'paper_width_mm' => $paper->width_mm, 'paper_height_mm' => $paper->height_mm, 'theoretical_capacity' => $capacity, 'layout_review' => $parameters['pieces_per_sheet'] > $capacity, 'paper_sheets' => $sheets, 'minimum_sheets' => $minimumSheets, 'rows' => $rows, 'materials_milli_iqd' => $materials, 'other_milli_iqd' => $other, 'total_milli_iqd' => $total, 'unit_milli_iqd' => CostingMath::mulDiv($total, 1, $parameters['quantity']), 'stock_sufficient' => ! collect($rows)->contains(fn (array $row): bool => ! $row['sufficient']), 'estimated_surplus_milli_iqd' => $parameters['sale_price_iqd'] === null ? null : $parameters['sale_price_iqd'] * $parameters['quantity'] * 1000 - $total];
    }

    public function produce(array $input): CostRun
    {
        $identity = Validator::make($input, ['request_id' => 'required|uuid', 'confirm_stock' => 'accepted', 'recipe_id' => 'nullable|integer|exists:cost_recipes,id', 'reference' => 'nullable|string|max:160'])->validate();
        $parameters = $this->parameters($input);
        $hash = hash('sha256', json_encode($parameters + ['recipe_id' => $identity['recipe_id'] ?? null, 'reference' => $identity['reference'] ?? null], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($identity, $parameters, $hash): CostRun {
            $existing = CostRun::where('request_id', $identity['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->submission_hash, $hash), 409);
                abort_if($existing->voided_at, 409, 'الدفعة ملغاة. افتح الحاسبة لتسجيل دفعة جديدة.');

                return $existing;
            }
            $items = StockItem::whereIn('id', $this->itemIds($parameters))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = CostRun::where('request_id', $identity['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->submission_hash, $hash), 409);
                abort_if($existing->voided_at, 409, 'الدفعة ملغاة. افتح الحاسبة لتسجيل دفعة جديدة.');

                return $existing;
            }
            $result = $this->calculate($parameters, $items);
            if (! $result['stock_sufficient']) {
                throw ValidationException::withMessages(['stock' => 'رصيد بعض المواد لا يكفي. لم يُسجل الإنتاج ولم يُخصم شيء.']);
            }
            $run = CostRun::firstOrCreate(['request_id' => $identity['request_id']], ['submission_hash' => $hash, 'cost_recipe_id' => $identity['recipe_id'] ?? null, 'reference' => $identity['reference'] ?? null, 'quantity' => $parameters['quantity'], 'total_milli_iqd' => $result['total_milli_iqd'], 'snapshot' => $result, 'user_id' => auth()->id()]);
            abort_unless(hash_equals($run->submission_hash, $hash), 409);
            if (! $run->wasRecentlyCreated) {
                return $run;
            }
            foreach ($result['rows'] as $row) {
                $this->inventory->consume($items->get($row['stock_item_id']), $row['quantity_milli'], ['type' => 'production', 'cost_run_id' => $run->id, 'note' => $run->number]);
            }
            $this->inventory->audit('production.consumed', 'CostRun', $run->id, ['quantity' => $run->quantity, 'total_milli_iqd' => $run->total_milli_iqd]);

            return $run;
        });
    }

    public function voidRun(CostRun $run, string $reason): void
    {
        DB::transaction(function () use ($run, $reason): void {
            $record = CostRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($record->voided_at) {
                return;
            }
            foreach ($record->movements()->orderBy('stock_item_id')->get() as $movement) {
                $this->inventory->reverse($movement);
            }
            $record->update(['voided_at' => now(), 'void_reason' => $reason]);
            $this->inventory->audit('production.voided', 'CostRun', $record->id, ['reason' => $reason]);
        });
    }
}
