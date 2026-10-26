<?php

namespace Database\Factories;

use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['request_id' => (string) Str::uuid(), 'stock_item_id' => StockItem::factory()->state(['quantity_milli' => 1000, 'value_milli_iqd' => 100000, 'valuation_quantity_milli' => 1000, 'valuation_value_milli_iqd' => 100000]), 'type' => 'purchase', 'quantity_milli' => 1000, 'value_milli_iqd' => 100000, 'basis_before_quantity_milli' => 0, 'basis_before_value_milli_iqd' => 0, 'balance_milli' => 1000, 'balance_value_milli_iqd' => 100000];
    }
}
