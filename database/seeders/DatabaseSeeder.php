<?php

namespace Database\Seeders;

use App\Models\CardTemplate;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Finish;
use App\Models\Governorate;
use App\Models\Material;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingRate;
use App\Models\TemplateVersion;
use App\Services\StoreSettings;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['عراقي' => 'iraqi', 'عبارات' => 'quotes', 'فن' => 'art', 'ألعاب' => 'gaming', 'سيارات' => 'cars', 'حيوانات' => 'animals', 'بسيط' => 'minimal'] as $name => $slug) {
            Category::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
        foreach ([
            ['afterhours', 'بعد منتصف الليل', 'AFTER HOURS', 'art'], ['baghdad', 'من بغداد، بحب', 'BAGHDAD EDITION', 'iraqi'], ['orbit', 'خارج المدار', 'OUT OF ORBIT', 'art'], ['nope', 'مو اليوم', 'NOPE.', 'quotes'], ['checker', 'خارج المسار', 'OFF GRID', 'cars'], ['bloom', 'مزاج ربيع', 'IN FULL BLOOM', 'art'], ['tiger', 'روح برية', 'WILD STRIPES', 'animals'], ['minimal', 'أقل، بصوت أعلى', 'LESS. BUT LOUDER.', 'minimal'],
        ] as [$art,$name,$description,$category]) {
            Product::withTrashed()->firstOrCreate(['slug' => $art], ['name' => $name, 'description' => $description, 'art_key' => $art, 'category_id' => Category::where('slug', $category)->value('id')]);
        }
        Material::firstOrCreate(['code' => 'printed-vinyl'], ['name' => 'خامة مطبوعة — بانتظار الاعتماد', 'notes' => 'فعّل الخامة بعد اختبار المطبعة.']);
        Material::firstOrCreate(['code' => 'clear-vinyl'], ['name' => 'شفاف غير مطبوع — بانتظار الاعتماد', 'transparent' => true]);
        Finish::firstOrCreate(['name' => 'مطفي']);
        Finish::firstOrCreate(['name' => 'لامع']);
        foreach ([['قالب مع شريحة صغيرة', 'front', 'small_chip', [['x' => 10, 'y' => 19, 'width' => 11, 'height' => 9, 'radius' => 1.4]]], ['قالب بدون شريحة', 'front', 'no_chip', []], ['خلفي مع استثناء الشريط — تجريبي', 'back', null, [['x' => 0, 'y' => 7, 'width' => 85.6, 'height' => 12, 'radius' => 0]]]] as [$name,$side,$kind,$holes]) {
            $t = CardTemplate::firstOrCreate(['side' => $side, 'kind' => $kind], ['name' => $name, 'active' => $side === 'front']);
            TemplateVersion::firstOrCreate(['card_template_id' => $t->id, 'version' => 1], ['geometry' => ['card_width' => 85.6, 'card_height' => 53.98, 'width' => 85.6, 'height' => 53.98, 'radius' => 3.18, 'bleed' => 2, 'safe' => 3, 'holes' => $holes], 'compatibility' => ['modes' => ['single', 'double', 'mixed'], 'material_ids' => Material::pluck('id')->all(), 'finish_ids' => Finish::pluck('id')->all()]]);
        }
        foreach (['بغداد', 'البصرة', 'نينوى', 'أربيل', 'النجف', 'كربلاء', 'السليمانية', 'دهوك', 'كركوك', 'الأنبار', 'بابل', 'ديالى', 'واسط', 'صلاح الدين', 'ذي قار', 'ميسان', 'المثنى', 'القادسية', 'حلبجة'] as $name) {
            $g = Governorate::firstOrCreate(['name' => $name]);
            ShippingRate::firstOrCreate(['governorate_id' => $g->id, 'area_id' => null]);
        }
        foreach (['privacy' => 'سياسة الخصوصية', 'terms' => 'الشروط والأحكام', 'returns' => 'سياسة الإلغاء والاسترجاع', 'shipping' => 'سياسة التوصيل'] as $slug => $title) {
            ContentPage::firstOrCreate(['slug' => $slug], ['title' => $title]);
        }
        $defaults = ['accepting_orders' => false, 'cod_enabled' => true, 'instagram' => null, 'whatsapp' => null, 'contact_email' => null, 'contact_address' => null, 'price_single_iqd' => null, 'price_double_iqd' => null, 'price_mixed_iqd' => null, 'extra_double_iqd' => null, 'extra_mixed_iqd' => null, 'print_profile' => ['color_space' => 'sRGB', 'ppi' => 300, 'icc' => null, 'pdf_standard' => 'PDF', 'white_ink' => false, 'mirror' => false], 'faq' => [['q' => 'شنو هو ستيكر البطاقة؟', 'a' => 'ملصق تختار تصميمه وتثبته على بطاقتك الموجودة عندك. المتجر لا يصدر بطاقات مصرفية.'], ['q' => 'أرفع صورة بطاقتي المصرفية؟', 'a' => 'لا. ارفع فقط الصورة أو الرسم الذي تريد طباعته. لا نحتاج رقم البطاقة أو تاريخها أو CVV.'], ['q' => 'أكدر أصمّم كل جهة بشكل مختلف؟', 'a' => 'نعم، اختار وجهين وخصص الأمام والخلف بشكل مستقل. خيار الشفاف يتضمن ملصقاً شفافاً غير مطبوع وملصقاً مطبوعاً للجهة الثانية.'], ['q' => 'شلون أختار فتحة الشريحة؟', 'a' => 'قارن الرسومات في دليل القوالب ببطاقتك دون إرسال صورتها. إذا لم تجد تطابقاً، أرسل طلب مراجعة.']]];
        foreach ($defaults as $key => $value) {
            if (! Setting::find($key)) {
                StoreSettings::put($key, $value);
            }
        }
    }
}
