<?php

namespace Database\Factories;

use App\Models\DeviceModel;
use App\Models\ProductSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceModel>
 */
class DeviceModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['product_section_id' => ProductSection::factory(), 'name' => 'Test device model', 'slug' => fake()->unique()->slug(), 'manufacturer' => 'Test maker', 'active' => true];
    }
}
