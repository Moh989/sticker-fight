<?php

namespace Tests\Feature;

use App\Models\CostRecipe;
use App\Models\CostRun;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StickerCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StickerCostTest extends TestCase
{
    use RefreshDatabase;

    private StockItem $paper;

    private StockItem $ink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->paper = StockItem::factory()->create(['name' => 'ورق A4']);
        $this->ink = StockItem::factory()->create(['name' => 'حبر', 'unit' => 'ml', 'width_mm' => null, 'height_mm' => null]);
        $this->buy($this->paper, '100', 20000);
        $this->buy($this->ink, '100', 30000);
    }

    private function buy(StockItem $item, string $quantity, int $amount): void
    {
        app(InventoryService::class)->post(['request_id' => (string) Str::uuid(), 'type' => 'material_purchase', 'title' => $item->name, 'occurred_on' => '2026-10-25', 'amount_iqd' => $amount, 'stock_item_id' => $item->id, 'quantity' => $quantity]);
    }

    /** @return array<string, mixed> */
    private function parameters(array $overrides = []): array
    {
        return array_replace(['paper_id' => $this->paper->id, 'width_mm' => '85.6', 'height_mm' => '53.98', 'pieces_per_sheet' => 8, 'print_layers' => 1, 'quantity' => 8, 'bleed_mm' => '2', 'margin_mm' => '0', 'gap_mm' => '0', 'waste_percent' => '0', 'other_per_sheet_iqd' => 100, 'other_per_piece_iqd' => 50, 'components' => [['stock_item_id' => $this->ink->id, 'quantity' => '0.5', 'basis' => 'per_sheet']]], $overrides);
    }

    /** @return array<string, mixed> */
    private function production(array $overrides = []): array
    {
        return $this->parameters(['request_id' => (string) Str::uuid(), 'confirm_stock' => 1, 'reference' => 'دفعة اختبار', ...$overrides]);
    }

    public function test_manual_yield_controls_cost_and_estimates_do_not_consume_stock(): void
    {
        $result = app(StickerCostService::class)->estimate($this->parameters(['sale_price_iqd' => 1000]));
        $this->assertSame(10, $result['theoretical_capacity']);
        $this->assertSame(8, $result['parameters']['pieces_per_sheet']);
        $this->assertSame(1, $result['paper_sheets']);
        $this->assertSame(350000, $result['materials_milli_iqd']);
        $this->assertSame(500000, $result['other_milli_iqd']);
        $this->assertSame(850000, $result['total_milli_iqd']);
        $this->assertSame(106250, $result['unit_milli_iqd']);
        $this->assertSame(7150000, $result['estimated_surplus_milli_iqd']);
        $this->assertTrue($result['stock_sufficient']);
        $this->postJson('/admin/accounting/costing/calculate', $this->parameters())->assertOk()->assertJsonPath('stock_sufficient', true)->assertJsonPath('html', fn (string $html): bool => str_contains($html, '106.25'));
        $this->assertSame(100000, $this->paper->fresh()->quantity_milli);
        $this->assertDatabaseCount('cost_runs', 0);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('orders', 0);
    }

    #[TestWith([['quantity' => 9], 2])]
    #[TestWith([['print_layers' => 2], 2])]
    #[TestWith([['waste_percent' => '10'], 2])]
    #[TestWith([['waste_percent' => '50'], 2])]
    #[TestWith([['waste_percent' => '10', 'actual_sheets' => 1], 1])]
    #[TestWith([['actual_sheets' => 3], 3])]
    public function test_whole_sheet_rounding_layers_waste_and_actual_usage(array $overrides, int $sheets): void
    {
        $result = app(StickerCostService::class)->estimate($this->parameters($overrides));
        $this->assertSame($sheets, $result['paper_sheets']);
        $this->assertSame(500 * $sheets, $result['rows'][1]['quantity_milli']);
        $this->assertSame(150000 * $sheets, $result['rows'][1]['cost_milli_iqd']);
    }

    public function test_per_sticker_components_use_finished_quantity_even_for_two_printed_layers(): void
    {
        $result = app(StickerCostService::class)->estimate($this->parameters(['print_layers' => 2, 'components' => [['stock_item_id' => $this->ink->id, 'quantity' => '0.125', 'basis' => 'per_piece']]]));
        $this->assertSame(1000, $result['rows'][1]['quantity_milli']);
        $this->assertSame(300000, $result['rows'][1]['cost_milli_iqd']);
        $this->assertSame(1300000, $result['total_milli_iqd']);
    }

    public function test_production_is_idempotent_and_keeps_a_frozen_snapshot_of_real_stock_cost(): void
    {
        $payload = $this->production();
        $this->post('/admin/accounting/production', $payload)->assertRedirect(route('accounting.production'));
        $run = CostRun::firstOrFail();
        $snapshot = $run->snapshot;
        $this->post('/admin/accounting/production', $payload)->assertRedirect();
        $this->postJson('/admin/accounting/production', [...$payload, 'quantity' => 16])->assertConflict();
        $this->assertSame(99000, $this->paper->fresh()->quantity_milli);
        $this->assertSame(19800000, $this->paper->fresh()->value_milli_iqd);
        $this->assertSame(99500, $this->ink->fresh()->quantity_milli);
        $this->assertSame(29850000, $this->ink->fresh()->value_milli_iqd);
        $this->assertSame(850000, $run->total_milli_iqd);
        $this->assertDatabaseCount('cost_runs', 1);
        $this->assertDatabaseCount('stock_movements', 4);
        $this->buy($this->paper, '100', 40000);
        $this->assertSame($snapshot, $run->fresh()->snapshot);
        $this->assertGreaterThan(850000, app(StickerCostService::class)->estimate($this->parameters())['total_milli_iqd']);
        $this->get('/admin/accounting/production?q='.$run->number)->assertOk()->assertSee($run->number)->assertSee('106.25');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_shortage_prevents_the_whole_batch_without_partial_deductions(): void
    {
        $parameters = $this->parameters(['components' => [['stock_item_id' => $this->ink->id, 'quantity' => '101', 'basis' => 'per_sheet']]]);
        $this->assertFalse(app(StickerCostService::class)->estimate($parameters)['stock_sufficient']);
        $this->postJson('/admin/accounting/production', $parameters + ['request_id' => (string) Str::uuid(), 'confirm_stock' => 1])->assertUnprocessable()->assertJsonValidationErrors('stock');
        $this->assertSame(100000, $this->paper->fresh()->quantity_milli);
        $this->assertSame(100000, $this->ink->fresh()->quantity_milli);
        $this->assertDatabaseCount('cost_runs', 0);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_later_batches_must_be_reversed_first_and_all_original_values_are_restored(): void
    {
        $firstPayload = $this->production();
        $first = app(StickerCostService::class)->produce($firstPayload);
        $second = app(StickerCostService::class)->produce($this->production());
        $this->postJson('/admin/accounting/production/'.$first->id.'/void', ['reason' => 'تصحيح الدفعة'])->assertUnprocessable();
        $this->assertNull($first->fresh()->voided_at);
        $this->post('/admin/accounting/production/'.$second->id.'/void', ['reason' => 'تصحيح الدفعة'])->assertRedirect();
        $this->post('/admin/accounting/production/'.$first->id.'/void', ['reason' => 'تصحيح الدفعة'])->assertRedirect();
        $this->post('/admin/accounting/production/'.$first->id.'/void', ['reason' => 'تصحيح الدفعة'])->assertRedirect();
        $this->postJson('/admin/accounting/production', $firstPayload)->assertConflict();
        $this->assertSame(100000, $this->paper->fresh()->quantity_milli);
        $this->assertSame(20000000, $this->paper->fresh()->value_milli_iqd);
        $this->assertSame(100000, $this->ink->fresh()->quantity_milli);
        $this->assertSame(30000000, $this->ink->fresh()->value_milli_iqd);
        $this->assertSame(4, StockMovement::where('type', 'reversal')->count());
    }

    public function test_last_known_cost_survives_depletion_but_production_requires_stock(): void
    {
        app(StickerCostService::class)->produce($this->production(['quantity' => 800, 'components' => []]));
        $this->assertSame(0, $this->paper->fresh()->quantity_milli);
        $this->assertSame(0, $this->paper->fresh()->value_milli_iqd);
        $result = app(StickerCostService::class)->estimate($this->parameters(['components' => []]));
        $this->assertSame(200000, $result['rows'][0]['cost_milli_iqd']);
        $this->assertFalse($result['stock_sufficient']);
        $this->postJson('/admin/accounting/production', $this->production())->assertUnprocessable();
        $this->assertDatabaseCount('cost_runs', 1);
    }

    public function test_recipes_store_inputs_without_live_quantity_or_prices_and_reload_the_current_stock_cost(): void
    {
        $this->post('/admin/accounting/costing/recipes', $this->parameters(['recipe_name' => 'بطاقات A4', 'actual_sheets' => 3, 'sale_price_iqd' => 1000]))->assertRedirect();
        $recipe = CostRecipe::firstOrFail();
        $this->assertSame(8, $recipe->parameters['pieces_per_sheet']);
        foreach (['quantity', 'actual_sheets', 'sale_price_iqd'] as $key) {
            $this->assertArrayNotHasKey($key, $recipe->parameters);
        }
        $this->get('/admin/accounting/costing?recipe='.$recipe->id)->assertOk()->assertSee('بطاقات A4');
        $this->assertDatabaseCount('cost_runs', 0);
    }

    #[TestWith(['pieces_per_sheet', 100])]
    #[TestWith(['width_mm', '3000'])]
    #[TestWith(['actual_sheets', 0])]
    #[TestWith(['waste_percent', '50.01'])]
    #[TestWith(['quantity', 0])]
    public function test_invalid_cost_parameters_are_rejected(string $key, mixed $value): void
    {
        $this->postJson('/admin/accounting/costing/calculate', $this->parameters([$key => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_duplicate_or_unpriced_materials_and_insufficient_actual_sheets_are_rejected(): void
    {
        $unpriced = StockItem::factory()->create();
        $inputs = [
            ['components' => [['stock_item_id' => $this->paper->id, 'quantity' => 1]]],
            ['paper_id' => $unpriced->id],
            ['print_layers' => 2, 'actual_sheets' => 1],
            ['components' => [['stock_item_id' => $this->ink->id, 'quantity' => 0]]],
        ];
        foreach ($inputs as $override) {
            $this->postJson('/admin/accounting/costing/calculate', $this->parameters($override))->assertUnprocessable();
        }
        $this->ink->update(['active' => false]);
        $this->postJson('/admin/accounting/costing/calculate', $this->parameters())->assertUnprocessable();
        $this->postJson('/admin/accounting/production', $this->parameters() + ['request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('confirm_stock');
        $this->assertDatabaseCount('cost_runs', 0);
    }

    public function test_customer_material_names_are_escaped_in_calculated_html(): void
    {
        $this->ink->update(['name' => '<script>alert(1)</script>']);
        $this->postJson('/admin/accounting/costing/calculate', $this->parameters())->assertOk()->assertJsonPath('html', fn (string $html): bool => ! str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'));
    }

    public function test_saved_production_cost_cannot_be_rewritten(): void
    {
        $run = app(StickerCostService::class)->produce($this->production());
        $this->expectException(\LogicException::class);
        $run->update(['total_milli_iqd' => 1]);
    }
}
