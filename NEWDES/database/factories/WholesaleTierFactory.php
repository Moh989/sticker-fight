<?php

namespace Database\Factories;

use App\Models\ProductSection;
use App\Models\WholesaleTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WholesaleTier>
 */
class WholesaleTierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['product_section_id' => ProductSection::factory()->state(['audience' => 'business']), 'label' => 'Test 50 mm matte', 'minimum_quantity' => 100, 'unit_price_iqd' => 200, 'active' => true];
    }
}
