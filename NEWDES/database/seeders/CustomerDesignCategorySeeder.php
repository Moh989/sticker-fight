<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Services\CustomerDesignPublisher;
use Illuminate\Database\Seeder;

class CustomerDesignCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Category::firstOrCreate(['slug' => CustomerDesignPublisher::CATEGORY_SLUG], ['name' => 'تصاميمكم اللطيفة', 'active' => true]);
    }
}
