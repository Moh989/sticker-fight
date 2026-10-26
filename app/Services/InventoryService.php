<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\AccountingPayment;
use App\Models\AuditLog;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function post(array $input): AccountingEntry
    {
        $data = Validator::make($input, [
            'request_id' => 'required|uuid', 'type' => ['required', Rule::in(array_keys(AccountingEntry::TYPES))],
            'title' => 'required|string|max:160', 'supplier' => 'nullable|string|max:160', 'occurred_on' => 'required|date_format:Y-m-d',
            'amount_iqd' => 'required|integer|between:0,1000000000', 'paid_iqd' => 'nullable|integer|between:0,1000000000',
            'stock_item_id' => 'nullable|required_if:type,material_purchase|integer|exists:stock_items,id',
            'quantity' => 'nullable|required_if:type,material_purchase|numeric|between:0.001,1000000|decimal:0,3',
            'notes' => 'nullable|string|max:2000',
        ])->validate();
        $type = $data['type'];
        $amount = (int) $data['amount_iqd'];
        $paid = isset($data['paid_iqd']) ? (int) $data['paid_iqd'] : $amount;
        if (in_array($type, ['capital_in', 'capital_out'], true)) {
            $paid = $amount;
        }
        if ($paid > $amount || ($amount === 0 && $type !== 'material_purchase')) {
            throw ValidationException::withMessages(['amount_iqd' => 'راجع المبلغ الكلي والمدفوع؛ المدفوع لا يتجاوز الكلي.']);
        }
        $payload = [
            'type' => $type, 'title' => trim($data['title']), 'supplier' => $data['supplier'] ?? null, 'occurred_on' => $data['occurred_on'],
            'amount_iqd' => $amount, 'stock_item_id' => $type === 'material_purchase' ? (int) $data['stock_item_id'] : null,
            'quantity_milli' => $type === 'material_purchase' ? CostingMath::scaled((string) $data['quantity']) : null, 'notes' => $data['notes'] ?? null,
        ];
        $hash = hash('sha256', json_encode($payload + ['initial_paid' => $paid], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($payload, $data, $hash, $paid): AccountingEntry {
            $entry = AccountingEntry::firstOrCreate(['request_id' => $data['request_id']], $payload + ['paid_iqd' => 0, 'user_id' => auth()->id(), 'submission_hash' => $hash]);
            abort_unless(hash_equals($entry->submission_hash, $hash), 409, 'أُرسل هذا القيد سابقاً بتفاصيل مختلفة.');
            if (! $entry->wasRecentlyCreated) {
                abort_if($entry->voided_at, 409, 'القيد ملغى. حدّث الصفحة لتسجيل عملية جديدة.');

                return $entry;
            }
            if ($entry->type === 'material_purchase') {
                $item = StockItem::whereKey($entry->stock_item_id)->lockForUpdate()->firstOrFail();
                if (! $item->active) {
                    throw ValidationException::withMessages(['stock_item_id' => 'المادة موقوفة. فعّلها أو اختر مادة أخرى.']);
                }
                $this->move($item, $entry->quantity_milli, $entry->amount_iqd * 1000, ['type' => 'purchase', 'accounting_entry_id' => $entry->id, 'note' => $entry->title]);
            }
            if ($paid > 0) {
                AccountingPayment::create(['request_id' => $entry->request_id, 'accounting_entry_id' => $entry->id, 'amount_iqd' => $paid, 'paid_on' => $data['occurred_on'], 'user_id' => auth()->id()]);
                $entry->update(['paid_iqd' => $paid]);
            }
            $this->audit('accounting.posted', 'AccountingEntry', $entry->id, ['type' => $entry->type, 'amount_iqd' => $entry->amount_iqd]);

            return $entry;
        });
    }

    public function pay(AccountingEntry $entry, array $input): void
    {
        $data = Validator::make($input, ['request_id' => 'required|uuid', 'amount_iqd' => 'required|integer|between:1,1000000000', 'paid_on' => 'required|date_format:Y-m-d'])->validate();
        DB::transaction(function () use ($entry, $data): void {
            $record = AccountingEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($record->voided_at) {
                throw ValidationException::withMessages(['payment' => 'القيد ملغى؛ لا يمكن تسجيل دفعة له.']);
            }
            $existing = AccountingPayment::where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->accounting_entry_id === $record->id && $existing->amount_iqd === (int) $data['amount_iqd'] && $existing->paid_on->format('Y-m-d') === $data['paid_on'], 409, 'مفتاح الدفعة مستخدم بتفاصيل مختلفة.');

                return;
            }
            if ((int) $data['amount_iqd'] > $record->amount_iqd - $record->paid_iqd) {
                throw ValidationException::withMessages(['amount_iqd' => 'الدفعة تتجاوز المبلغ المتبقي.']);
            }
            $payment = AccountingPayment::firstOrCreate(['request_id' => $data['request_id']], $data + ['accounting_entry_id' => $record->id, 'user_id' => auth()->id()]);
            abort_unless($payment->accounting_entry_id === $record->id && $payment->amount_iqd === (int) $data['amount_iqd'] && $payment->paid_on->format('Y-m-d') === $data['paid_on'], 409);
            if (! $payment->wasRecentlyCreated) {
                return;
            }
            $record->update(['paid_iqd' => $record->paid_iqd + (int) $data['amount_iqd']]);
            $this->audit('accounting.payment', 'AccountingEntry', $record->id, ['amount_iqd' => (int) $data['amount_iqd']]);
        });
    }

    public function waste(StockItem $item, array $input): void
    {
        $data = Validator::make($input, ['request_id' => 'required|uuid', 'quantity' => 'required|numeric|between:0.001,1000000|decimal:0,3', 'note' => 'required|string|min:3|max:500'])->validate();
        $quantity = CostingMath::scaled((string) $data['quantity']);
        DB::transaction(function () use ($item, $data, $quantity): void {
            $record = StockItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $existing = StockMovement::where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->stock_item_id === $record->id && $existing->type === 'waste' && $existing->quantity_milli === -$quantity && $existing->note === $data['note'], 409);
                abort_if($existing->reversal()->exists(), 409, 'الحركة معكوسة. حدّث الصفحة لتسجيل استهلاك جديد.');

                return;
            }
            $this->consume($record, $quantity, ['request_id' => $data['request_id'], 'type' => 'waste', 'note' => $data['note']]);
            $this->audit('stock.waste', 'StockItem', $record->id, ['quantity_milli' => $quantity]);
        });
    }

    public function consume(StockItem $item, int $quantity, array $attributes): StockMovement
    {
        if ($quantity <= 0 || $quantity > $item->quantity_milli) {
            throw ValidationException::withMessages(['stock' => 'رصيد «'.$item->name.'» لا يكفي. لم يُخصم شيء.']);
        }
        $cost = CostingMath::mulDiv($item->value_milli_iqd, $quantity, $item->quantity_milli);

        return $this->move($item, -$quantity, -$cost, $attributes);
    }

    public function voidEntry(AccountingEntry $entry, string $reason): void
    {
        DB::transaction(function () use ($entry, $reason): void {
            $record = AccountingEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($record->voided_at) {
                return;
            }
            if ($record->type === 'material_purchase') {
                $movement = StockMovement::where('accounting_entry_id', $record->id)->firstOrFail();
                $this->reverse($movement);
            }
            $record->update(['voided_at' => now(), 'void_reason' => $reason]);
            $this->audit('accounting.voided', 'AccountingEntry', $record->id, ['reason' => $reason]);
        });
    }

    public function reverse(StockMovement $movement): void
    {
        $item = StockItem::whereKey($movement->stock_item_id)->lockForUpdate()->firstOrFail();
        if (StockMovement::where('reversal_of', $movement->id)->exists()) {
            return;
        }
        if ((int) $item->movements()->where('type', '!=', 'reversal')->whereDoesntHave('reversal')->max('id') !== $movement->id) {
            throw ValidationException::withMessages(['reversal' => 'توجد حركات أحدث لهذه المادة. اعكس الأحدث أولاً حتى تبقى كلفة المخزن صحيحة.']);
        }
        $this->move($item, -$movement->quantity_milli, -$movement->value_milli_iqd, ['type' => 'reversal', 'reversal_of' => $movement->id, 'note' => 'عكس الحركة #'.$movement->id]);
        $item->update(['valuation_quantity_milli' => $movement->basis_before_quantity_milli, 'valuation_value_milli_iqd' => $movement->basis_before_value_milli_iqd]);
    }

    private function move(StockItem $item, int $quantity, int $value, array $attributes): StockMovement
    {
        $balance = $item->quantity_milli + $quantity;
        $balanceValue = $item->value_milli_iqd + $value;
        if ($balance < 0 || $balanceValue < 0 || $balance > 1000000000000 || $balanceValue > 9000000000000000) {
            throw ValidationException::withMessages(['stock' => 'راجع كمية المادة وقيمتها؛ الحركة تتجاوز الرصيد أو حدود التخزين.']);
        }
        $beforeQuantity = $item->valuation_quantity_milli;
        $beforeValue = $item->valuation_value_milli_iqd;
        $update = ['quantity_milli' => $balance, 'value_milli_iqd' => $balanceValue];
        if ($balance > 0) {
            $update += ['valuation_quantity_milli' => $balance, 'valuation_value_milli_iqd' => $balanceValue];
        }
        $item->update($update);

        return StockMovement::create($attributes + ['request_id' => (string) Str::uuid(), 'stock_item_id' => $item->id, 'quantity_milli' => $quantity, 'value_milli_iqd' => $value, 'basis_before_quantity_milli' => $beforeQuantity, 'basis_before_value_milli_iqd' => $beforeValue, 'balance_milli' => $balance, 'balance_value_milli_iqd' => $balanceValue, 'user_id' => auth()->id()]);
    }

    public function audit(string $action, string $type, string|int $id, array $changes = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => (string) $id, 'changes' => $changes]);
    }
}
