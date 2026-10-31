<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\ReadyNamePersonalization;
use Illuminate\Database\Seeder;

class PersonalizedProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $category = Category::firstOrCreate(['slug' => 'your-name'], ['name' => 'تصاميم باسمك', 'active' => true]);
        Product::withTrashed()->firstOrCreate(['slug' => 'iraqi-name-card'], ['name' => 'باسمك — لمسة عراقية', 'description' => 'تصميم هادئ بلمسة من شبابيك الشناشيل. اكتب اسمك واختار الخط اللي يشبهك.', 'category_id' => $category->id, 'art_key' => 'name-card', 'price_iqd' => null, 'active' => true, 'personalization_enabled' => true, 'personalization_layout' => ReadyNamePersonalization::DEFAULT_LAYOUT]);
    }
}
