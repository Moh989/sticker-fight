<?php

namespace Database\Factories;

use App\Models\AccountingEntry;
use App\Models\AccountingPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountingPayment>
 */
class AccountingPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['request_id' => (string) Str::uuid(), 'accounting_entry_id' => AccountingEntry::factory()->state(['paid_iqd' => 1000]), 'amount_iqd' => 1000, 'paid_on' => now()->toDateString()];
    }
}
