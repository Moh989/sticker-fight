<?php

namespace Database\Factories;

use App\Models\AccountingEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountingEntry>
 */
class AccountingEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['request_id' => (string) Str::uuid(), 'submission_hash' => hash('sha256', fake()->uuid()), 'type' => 'equipment_purchase', 'title' => fake()->words(3, true), 'occurred_on' => now()->toDateString(), 'amount_iqd' => 10000, 'paid_iqd' => 0];
    }
}
