<?php

namespace Database\Seeders;

use App\Models\CardTemplate;
use App\Models\Category;
use App\Models\Finish;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductSection;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Services\StoreSettings;
use Illuminate\Database\Seeder;

class NewdesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing', 'qa') || ! config('sticker.demo_mode')) {
            throw new \RuntimeException('Demo fixtures require a local environment and STORE_DEMO_MODE=true.');
        }
        $this->call(DatabaseSeeder::class);
        foreach (Product::cardSkins()->get() as $index => $product) {
            $product->update(['is_demo' => true, 'price_iqd' => 8000, 'is_featured' => $index < 4, 'featured_order' => $index, 'active' => true]);
        }
        foreach (['single' => 8000, 'double' => 14000, 'mixed' => 12000] as $mode => $price) {
            StoreSettings::put('price_'.$mode.'_iqd', $price);
        }
        StoreSettings::put('extra_double_iqd', 6000);
        StoreSettings::put('extra_mixed_iqd', 4000);
        StoreSettings::put('accepting_orders', true);
        Material::where('code', 'printed-vinyl')->update(['active' => true, 'name' => 'فينيل مطبوع — تجريبي']);
        Material::where('code', 'clear-vinyl')->update(['active' => true, 'name' => 'شفاف غير مطبوع — تجريبي']);
        Finish::query()->update(['active' => true]);
        CardTemplate::query()->update(['active' => true]);
        TemplateVersion::query()->update(['approved_at' => now(), 'sample_reference' => 'DEMO SOFTWARE TEST ONLY — NOT PHYSICAL APPROVAL']);
        $template = TemplateVersion::whereHas('template', fn ($q) => $q->where('kind', 'small_chip'))->first();
        $geometry = $template->geometry;
        $geometry['holes'][0]['x'] = 9.1;
        $geometry['holes'][0]['y'] = 18.5;
        TemplateVersion::firstOrCreate(['card_template_id' => $template->card_template_id, 'version' => 2], ['geometry' => $geometry, 'compatibility' => $template->compatibility, 'approved_at' => now(), 'sample_reference' => 'DEMO SOFTWARE TEST ONLY — NOT PHYSICAL APPROVAL']);
        ShippingRate::query()->update(['active' => true, 'fee_iqd' => 5000, 'estimated_time' => null]);
        foreach (['playstation-controllers', 'xbox-controllers', 'playstation-consoles', 'xbox-consoles'] as $index => $slug) {
            $section = ProductSection::where('slug', $slug)->first();
            $product = Product::firstOrCreate(['slug' => 'demo-device-'.$slug], ['name' => 'خطوط هادئة · '.__($section->name), 'art_key' => 'minimal', 'product_section_id' => $section->id, 'category_id' => Category::where('slug', 'minimal')->value('id'), 'active' => true, 'is_demo' => true, 'description' => 'تصميم تجريبي. يراجع المتجر القص والموديل قبل تحديد السعر.']);
            $product->deviceModels()->syncWithoutDetaching($section->deviceModels()->pluck('id'));
        }
    }
}
