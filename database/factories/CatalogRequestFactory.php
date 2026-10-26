<?php

namespace Database\Factories;

use App\Models\CatalogRequest;
use App\Models\ProductSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CatalogRequest>
 */
class CatalogRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['request_id' => (string) Str::uuid(), 'product_section_id' => ProductSection::factory(), 'name' => fake()->name(), 'phone' => '+9647701234567', 'model' => 'PS5 DualSense', 'quantity' => 1, 'details' => fake()->sentence(), 'snapshot' => ['section' => 'أجهزة التحكم', 'product' => null], 'submission_hash' => hash('sha256', fake()->uuid()), 'status' => 'new'];
    }
}
