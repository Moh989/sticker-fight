<?php

namespace Database\Factories;

use App\Models\CostRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CostRun>
 */
class CostRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['request_id' => (string) Str::uuid(), 'submission_hash' => hash('sha256', fake()->uuid()), 'quantity' => 8, 'total_milli_iqd' => 1000000, 'snapshot' => ['parameters' => ['quantity' => 8], 'rows' => [], 'paper_sheets' => 1, 'theoretical_capacity' => 10, 'materials_milli_iqd' => 0, 'other_milli_iqd' => 1000000, 'total_milli_iqd' => 1000000, 'unit_milli_iqd' => 125000, 'stock_sufficient' => true, 'layout_review' => false, 'estimated_surplus_milli_iqd' => null]];
    }
}
