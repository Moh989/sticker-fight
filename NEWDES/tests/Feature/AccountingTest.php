<?php

namespace Tests\Feature;

use App\Models\AccountingEntry;
use App\Models\CostRun;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\StockItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function purchase(StockItem $item, array $overrides = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'type' => 'material_purchase', 'title' => 'شراء ورق', 'occurred_on' => '2026-10-25', 'amount_iqd' => 20000, 'paid_iqd' => 5000, 'stock_item_id' => $item->id, 'quantity' => '100'], $overrides);
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_material_receipts_update_stock_once_and_preserve_supplier_debt(): void
    {
        $this->admin();
        $item = StockItem::factory()->create();
        $payload = $this->purchase($item);
        $this->post('/admin/accounting/entries', $payload)->assertRedirect(route('accounting.entries'));
        $this->post('/admin/accounting/entries', $payload)->assertRedirect();
        $this->postJson('/admin/accounting/entries', [...$payload, 'amount_iqd' => 25000])->assertConflict();

        $entry = AccountingEntry::firstOrFail();
        $this->assertSame(100000, $item->fresh()->quantity_milli);
        $this->assertSame(20000000, $item->fresh()->value_milli_iqd);
        $this->assertSame(5000, $entry->paid_iqd);
        $this->assertDatabaseCount('accounting_entries', 1);
        $this->assertDatabaseCount('accounting_payments', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting.posted', 'subject_id' => $entry->id]);
        $this->get('/admin/accounting')->assertOk()->assertViewHas('remaining', 15000)->assertViewHas('cash', -5000)->assertViewHas('stockValue', 20000000);
        $this->get('/admin/accounting/entries?q='.$entry->number)->assertOk()->assertSee($entry->number);
        $this->get('/admin/accounting/stock/'.$item->id)->assertOk()->assertSee($item->name);
    }

    public function test_installments_are_idempotent_and_cannot_exceed_or_move_between_invoices(): void
    {
        $this->admin();
        $item = StockItem::factory()->create();
        $entry = app(InventoryService::class)->post($this->purchase($item));
        $payment = ['request_id' => (string) Str::uuid(), 'amount_iqd' => 10000, 'paid_on' => '2026-10-25'];
        $url = '/admin/accounting/entries/'.$entry->id.'/payments';
        $this->post($url, $payment)->assertRedirect();
        $this->post($url, $payment)->assertRedirect();
        $this->postJson($url, [...$payment, 'amount_iqd' => 10001])->assertConflict();
        $this->postJson($url, [...$payment, 'request_id' => (string) Str::uuid(), 'amount_iqd' => 5001])->assertUnprocessable();
        $other = AccountingEntry::factory()->create(['amount_iqd' => 20000]);
        $this->postJson('/admin/accounting/entries/'.$other->id.'/payments', $payment)->assertConflict();
        $this->assertSame(15000, $entry->fresh()->paid_iqd);
        $this->assertSame(0, $other->fresh()->paid_iqd);
        $this->assertDatabaseCount('accounting_payments', 2);
        $this->assertSame(100000, $item->fresh()->quantity_milli);
    }

    public function test_capital_and_equipment_are_separate_from_inventory_and_voided_records_remain_auditable(): void
    {
        $this->admin();
        $item = StockItem::factory()->create();
        $base = $this->purchase($item);
        foreach ([['capital_in', 100000, 0], ['equipment_purchase', 50000, 20000], ['expense', 5000, 5000], ['capital_out', 10000, 0]] as [$type, $amount, $paid]) {
            $this->post('/admin/accounting/entries', [...$base, 'request_id' => (string) Str::uuid(), 'type' => $type, 'amount_iqd' => $amount, 'paid_iqd' => $paid])->assertRedirect();
        }
        $this->get('/admin/accounting')->assertOk()->assertViewHas('cash', 65000)->assertViewHas('equipment', 50000)->assertViewHas('remaining', 30000);
        $this->assertSame(0, $item->fresh()->quantity_milli);
        $this->assertDatabaseCount('stock_movements', 0);
        $entry = AccountingEntry::where('type', 'equipment_purchase')->firstOrFail();
        $this->post('/admin/accounting/entries/'.$entry->id.'/void', ['reason' => 'تصحيح تسجيل جهاز'])->assertRedirect();
        $this->get('/admin/accounting')->assertOk()->assertViewHas('cash', 85000)->assertViewHas('equipment', 0)->assertViewHas('remaining', 0);
        $this->assertDatabaseCount('accounting_entries', 4);
        $this->assertDatabaseCount('accounting_payments', 4);
    }

    public function test_weighted_cost_and_reversals_restore_exact_value_in_reverse_chronological_order(): void
    {
        $this->admin();
        $item = StockItem::factory()->create();
        $first = app(InventoryService::class)->post($this->purchase($item));
        $secondPayload = $this->purchase($item, ['amount_iqd' => 30000]);
        $second = app(InventoryService::class)->post($secondPayload);
        $waste = ['request_id' => (string) Str::uuid(), 'quantity' => '0.5', 'note' => 'ورق تالف'];
        $this->post('/admin/accounting/stock/'.$item->id.'/waste', $waste)->assertRedirect();
        $this->post('/admin/accounting/stock/'.$item->id.'/waste', $waste)->assertRedirect();
        $this->assertSame(199500, $item->fresh()->quantity_milli);
        $this->assertSame(49875000, $item->fresh()->value_milli_iqd);
        $this->postJson('/admin/accounting/entries/'.$second->id.'/void', ['reason' => 'تصحيح الشراء'])->assertUnprocessable();
        $this->assertNull($second->fresh()->voided_at);
        $movement = StockMovement::where('type', 'waste')->firstOrFail();
        $reverseUrl = '/admin/accounting/movements/'.$movement->id.'/reverse';
        $this->post($reverseUrl, ['reason' => 'تصحيح التالف'])->assertRedirect();
        $this->post($reverseUrl, ['reason' => 'تصحيح التالف'])->assertRedirect();
        $this->postJson('/admin/accounting/stock/'.$item->id.'/waste', $waste)->assertConflict();
        $this->post('/admin/accounting/entries/'.$second->id.'/void', ['reason' => 'تصحيح الشراء'])->assertRedirect();
        $this->assertSame(100000, $item->fresh()->quantity_milli);
        $this->assertSame(20000000, $item->fresh()->value_milli_iqd);
        $this->assertSame(20000000, $item->fresh()->valuation_value_milli_iqd);
        $this->post('/admin/accounting/entries/'.$first->id.'/void', ['reason' => 'تصحيح الشراء'])->assertRedirect();
        $this->postJson('/admin/accounting/entries', $secondPayload)->assertConflict();
        $this->assertSame(0, $item->fresh()->quantity_milli);
        $this->assertSame(0, $item->fresh()->value_milli_iqd);
        $this->assertSame(0, $item->fresh()->valuation_quantity_milli);
        $this->assertDatabaseCount('stock_movements', 6);
    }

    public function test_stock_cannot_be_fabricated_or_redefined_after_receipts(): void
    {
        $this->admin();
        $data = ['name' => 'ورق تجريبي', 'code' => 'test-paper', 'unit' => 'sheet', 'width_mm' => 210, 'height_mm' => 297, 'minimum' => '2.5', 'active' => 1, 'quantity_milli' => 999999, 'value_milli_iqd' => 1];
        $this->post('/admin/accounting/stock', $data)->assertRedirect();
        $item = StockItem::firstOrFail();
        $this->assertSame(0, $item->quantity_milli);
        $this->assertSame(0, $item->value_milli_iqd);
        $this->assertSame(2500, $item->minimum_milli);
        app(InventoryService::class)->post($this->purchase($item));
        $this->postJson('/admin/accounting/stock/'.$item->id, [...$data, 'width_mm' => 215])->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->post('/admin/accounting/stock/'.$item->id, [...$data, 'name' => 'اسم معدل'])->assertRedirect();
        $this->assertSame(100000, $item->fresh()->quantity_milli);
        $this->assertSame('210.00', $item->fresh()->width_mm);
        $this->postJson('/admin/accounting/stock/'.$item->id.'/waste', ['request_id' => (string) Str::uuid(), 'quantity' => 101, 'note' => 'أكبر من الرصيد'])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    #[TestWith(['quantity', '0'])]
    #[TestWith(['quantity', '1.0001'])]
    #[TestWith(['amount_iqd', '-1'])]
    #[TestWith(['paid_iqd', '20001'])]
    public function test_invalid_receipts_have_no_financial_or_stock_effect(string $key, string $value): void
    {
        $this->admin();
        $item = StockItem::factory()->create();
        $this->postJson('/admin/accounting/entries', $this->purchase($item, [$key => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('accounting_entries', 0);
        $this->assertDatabaseCount('accounting_payments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, $item->fresh()->quantity_milli);
    }

    public function test_seeded_materials_have_no_invented_stock_or_prices_and_keep_owner_changes(): void
    {
        $this->seed(StockItemSeeder::class);
        $paper = StockItem::where('code', 'adhesive-a4')->firstOrFail();
        $paper->update(['name' => 'ورق المالك', 'minimum_milli' => 20000]);
        $this->seed(StockItemSeeder::class);
        $this->assertDatabaseCount('stock_items', 2);
        $this->assertSame('ورق المالك', $paper->fresh()->name);
        $this->assertSame(0, StockItem::sum('quantity_milli'));
        $this->assertSame(0, StockItem::sum('value_milli_iqd'));
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    #[TestWith(['orders'])]
    #[TestWith(['production'])]
    #[TestWith(['customer'])]
    public function test_only_admin_can_read_or_change_accounting(string $role): void
    {
        $item = StockItem::factory()->create();
        $entry = AccountingEntry::factory()->create();
        $run = CostRun::factory()->create();
        $movement = StockMovement::factory()->create();
        $this->get('/admin/accounting')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => $role]));
        foreach (['', '/entries', '/stock', '/stock/'.$item->id, '/costing', '/production'] as $path) {
            $this->get('/admin/accounting'.$path)->assertForbidden();
        }
        foreach (['/entries', '/entries/'.$entry->id.'/payments', '/entries/'.$entry->id.'/void', '/stock', '/stock/'.$item->id, '/stock/'.$item->id.'/waste', '/movements/'.$movement->id.'/reverse', '/costing/calculate', '/costing/recipes', '/production', '/production/'.$run->id.'/void'] as $path) {
            $this->postJson('/admin/accounting'.$path, [])->assertForbidden();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_posted_entries_cannot_be_silently_rewritten(): void
    {
        $entry = AccountingEntry::factory()->create();
        $this->expectException(\LogicException::class);
        $entry->update(['amount_iqd' => 1]);
    }

    public function test_stock_history_cannot_be_deleted(): void
    {
        $movement = StockMovement::factory()->create();
        $this->expectException(\LogicException::class);
        $movement->delete();
    }
}
