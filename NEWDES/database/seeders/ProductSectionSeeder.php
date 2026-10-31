<?php

namespace Database\Seeders;

use App\Models\ProductSection;
use Illuminate\Database\Seeder;

class ProductSectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([
            ['controllers', 'أجهزة التحكم', 'controller', 'PlayStation، Xbox أو موديل يد التحكم', true, 'خلّي يد التحكم تشبهك. اذكر الموديل بالتحديد حتى نراجع القصّ ومناطق الأزرار قبل تأكيد الطلب.'],
            ['consoles', 'أجهزة الكونسل', 'console', 'اسم الجهاز وإصداره، مثل PS5 Slim أو Xbox Series X', true, 'منصة اللعب، بستايلك. نراجع موديل الجهاز والجهات المطلوبة وفتحات التهوية قبل تجهيز التصميم.'],
            ['advertising', 'ستيكرات إعلانية', 'advertising', null, false, 'شعار مشروعك، عرضك أو رسالتك. حدّد الاستخدام والمقاس والكمية، ونراجع متطلبات الطباعة وياك.'],
            ['hologram', 'ستيكرات هولوغرام', 'hologram', null, false, 'فكرة بلمسة لونية مختلفة. نوع الخامة وتأثيرها وشكل الطباعة يتأكد بعينة قبل اعتماد الطلب.'],
            ['laptops-tablets', 'لابتوبات وتابلت', 'laptop', 'موديل الجهاز وسنة الإصدار إن عُرفت', true, 'مساحة أكبر لتصميمك. نراجع موديل اللابتوب أو التابلت والسطح المطلوب قبل تحديد القالب.'],
            ['packaging', 'ملصقات التغليف', 'packaging', null, false, 'شعار للعلبة، رسالة شكر أو تعريف بمنتجك. اذكر نوع التغليف والمقاس والكمية المطلوبة.'],
            ['qr-stickers', 'ستيكرات QR', 'qr', null, false, 'وصلة لمشروعك على ملصق: قائمة، حساب أو رابط تختاره. المقاس ووضوح المسح يحتاجان مراجعة قبل الطباعة.'],
        ] as $index => [$slug, $name, $illustration, $hint, $requiresModel, $description]) {
            ProductSection::firstOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description, 'illustration' => $illustration, 'model_hint' => $hint, 'requires_model' => $requiresModel, 'sort_order' => $index + 1, 'active' => true]);
        }
    }
}
