<?php

namespace Database\Factories;

use App\Models\ProductSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSection>
 */
class ProductSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'slug' => fake()->unique()->slug(), 'description' => fake()->sentence(), 'illustration' => 'controller', 'model_hint' => 'PS5 DualSense / Xbox Series', 'requires_model' => true, 'sort_order' => 0, 'active' => true];
    }
}
