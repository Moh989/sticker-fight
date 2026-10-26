<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\GuestSession;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(), 'number' => 'SF-'.strtoupper(Str::random(12)),
            'tracking_hash' => hash('sha256', (string) Str::uuid()),
            'guest_session_id' => fn (): string => GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', (string) Str::uuid()), 'last_seen_at' => now()])->id,
            'customer_id' => Customer::factory(), 'request_id' => (string) Str::uuid(),
            'status' => 'pending_confirmation', 'financial_status' => 'unpaid',
            'address' => ['governorate' => 'بغداد', 'district' => 'المنصور', 'landmark' => 'نقطة اختبار'],
            'totals' => ['total_iqd' => 17000], 'notes' => null,
        ];
    }
}
