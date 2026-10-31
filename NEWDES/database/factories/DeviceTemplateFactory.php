<?php

namespace Database\Factories;

use App\Models\DeviceModel;
use App\Models\DeviceTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceTemplate>
 */
class DeviceTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['device_model_id' => DeviceModel::factory(), 'name' => 'Test device template', 'version' => 1, 'geometry' => ['width' => 150, 'height' => 80, 'bleed' => 2, 'safe' => 3, 'holes' => []], 'active' => true];
    }
}
