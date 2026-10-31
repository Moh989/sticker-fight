<?php

namespace Database\Factories;

use App\Models\CostRecipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostRecipe>
 */
class CostRecipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'parameters' => ['paper_id' => null, 'width_mm' => '85.6', 'height_mm' => '53.98', 'pieces_per_sheet' => 8, 'print_layers' => 1, 'bleed_mm' => '2', 'margin_mm' => '0', 'gap_mm' => '0', 'waste_percent' => '0', 'other_per_sheet_iqd' => 0, 'other_per_piece_iqd' => 0, 'components' => []]];
    }
}
