<?php

namespace Database\Factories;

use App\Models\StockItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItem>
 */
class StockItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'code' => fake()->unique()->slug(), 'unit' => 'sheet', 'width_mm' => '210.00', 'height_mm' => '297.00'];
    }
}
