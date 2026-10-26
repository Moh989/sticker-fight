<?php

namespace Database\Seeders;

use App\Models\StockItem;
use Illuminate\Database\Seeder;

class StockItemSeeder extends Seeder
{
    public function run(): void
    {
        StockItem::firstOrCreate(['code' => 'adhesive-a4'], ['name' => 'ورق A4 لاصق', 'unit' => 'sheet', 'width_mm' => '210.00', 'height_mm' => '297.00']);
        StockItem::firstOrCreate(['code' => 'printing-ink'], ['name' => 'حبر طباعة', 'unit' => 'ml']);
    }
}
