<?php

namespace Database\Seeders;

use App\Models\DeviceModel;
use App\Models\ProductSection;
use Illuminate\Database\Seeder;

class NewdesCatalogSeeder extends Seeder
{
    public function run(): void
    {
        ProductSection::whereIn('slug', ['advertising', 'hologram', 'packaging', 'qr-stickers'])->update(['audience' => 'business']);
        $restaurants = ProductSection::firstOrCreate(['slug' => 'restaurants'], ['name' => 'ستيكرات المطاعم والكافيهات', 'description' => 'ملصقات الأكواب والأكياس والعلب، بمقاس وخامة يناسبان استخدامك.', 'illustration' => 'packaging', 'requires_model' => false, 'audience' => 'business', 'active' => true, 'sort_order' => 5]);
        ProductSection::firstOrCreate(['slug' => 'other-business'], ['name' => 'طلبات مخصصة أخرى', 'description' => 'عندك فكرة مختلفة؟ اذكر الاستخدام والمقاس والكمية ونراجعها وياك.', 'illustration' => 'advertising', 'requires_model' => false, 'audience' => 'business', 'active' => true, 'sort_order' => 6]);
        $other = ProductSection::firstOrCreate(['slug' => 'other-devices'], ['name' => 'أجهزة أخرى', 'description' => 'اذكر الجهاز والموديل حتى نراجع إمكانية تنفيذه.', 'illustration' => 'laptop', 'requires_model' => true, 'active' => true, 'sort_order' => 9]);
        foreach ([['controllers', 'playstation-controllers', 'أيدي تحكم PlayStation', 'controller', [['dualsense', 'DualSense', 'Sony'], ['dualshock-4', 'DualShock 4', 'Sony']]], ['controllers', 'xbox-controllers', 'أيدي تحكم Xbox', 'controller', [['xbox-series-controller', 'Xbox Series Controller', 'Microsoft']]], ['consoles', 'playstation-consoles', 'أجهزة PlayStation', 'console', [['ps5-slim', 'PlayStation 5 Slim', 'Sony'], ['ps5', 'PlayStation 5', 'Sony']]], ['consoles', 'xbox-consoles', 'أجهزة Xbox', 'console', [['xbox-series-x', 'Xbox Series X', 'Microsoft'], ['xbox-series-s', 'Xbox Series S', 'Microsoft']]]] as [$parent,$slug,$name,$art,$models]) {
            $section = ProductSection::firstOrCreate(['slug' => $slug], ['parent_id' => ProductSection::where('slug', $parent)->value('id'), 'name' => $name, 'description' => 'اختار الموديل المتوافق قبل طلب مراجعة التصميم والقالب.', 'illustration' => $art, 'requires_model' => true, 'active' => true, 'sort_order' => 1]);
            foreach ($models as [$modelSlug,$modelName,$maker]) {
                DeviceModel::firstOrCreate(['slug' => $modelSlug], ['name' => $modelName, 'manufacturer' => $maker, 'product_section_id' => $section->id, 'active' => true]);
            }
        }
    }
}
