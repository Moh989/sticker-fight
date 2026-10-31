# Sticker Fight · NEWDES

واجهة نهارية بوردي غباري هادئ وبروح عراقية. البطاقة ذات الركن العلوي الأيمن المقشّر هي الرمز الثابت، ولا ينعكس في RTL. صور التصاميم تحتفظ بألوانها؛ الهوية لا تلوّن ملفات الزبائن.

| الاستخدام | اللون |
|---|---|
| الخلفية الأساسية | `#FAF9F6` |
| خلفيات الأقسام | `#F6EEF1` |
| الأفعال الأساسية والتحديد | `#AB4E70` |
| النص الأساسي | `#30262B` |
| النص الثانوي | `#705F67` |
| الحدود | `#E8DCE1` |

الرموز الموحدة في `resources/css/newdes.css`. البنية الوظيفية للمحرر والجداول في `app.css`، وتُحمّل قبل رموز NEWDES. الأزرار وردية غبارية بكتابة بيضاء، وتركيز لوحة المفاتيح ظاهر. الخط Cairo محلي مرخّص، وتوجد رخصته في `public/fonts`. العناوين متوسطة الثقل دون تباعد الحروف العربية. الحركات قصيرة، وتتعطل مع prefers-reduced-motion.

Wordmark مستقل بأحرف لاتينية، مع رمز بطاقة SVG، ونسخ أفقية ومدمجة وأحادية وPNG وOpen Graph في `public/brand`. إعادة إخراج PNG عبر `node scripts/export-brand.mjs`. رسوم الأقسام SVG أصلية بألوان محايدة ضمن `public/art/newdes-*.svg`؛ المعرض يعرض الرسومات الملونة الأصلية كما هي.

مسار الرئيسية: رسالة واضحة → منتجات مميزة → فئات → أكثر مبيعاً عند الاستحقاق فقط → تخصيص بثلاث خطوات → تركيب → خدمة العملاء. لا تستخدم صور العنف أو أرقاماً تسويقية افتراضية.

الطابع العراقي يظهر في رسم الشناشيل والنخلة وموج النهر والنجمة الثمانية، مع نصوص عراقية خفيفة ورابط للتصاميم العراقية. الزخارف تخص الواجهة فقط ولا تدخل في رسومات الزبائن أو ملفات الطباعة. اللون الأساسي #AB4E70، والتفاعل #8E3D59، والتظليل #FAEDF2.

خطوط الأسماء مستقلة عن خط الواجهة. للعربي: Cairo وTajawal وAmiri وRakkas وEl Messiri، إضافة إلى عائلة ثمانية بنسخ Sans والعناوين والنصوص. للإنجليزي: Cairo وLato وBebas Neue وPacifico وPlayfair Display، مع نسخ ثمانية الثلاث. السجل الموحد في `resources/design-fonts.json`؛ الخطوط المفتوحة وتراخيصها في `public/fonts`. ملفات ثمانية الأصلية تبقى خاصة، وعينات القائمة صور مرسومة بالخط وليست ملفات خطوط.

مصادر الخطوط المفتوحة وتراخيصها: [Tajawal](https://github.com/google/fonts/tree/main/ofl/tajawal)، [Amiri](https://github.com/google/fonts/tree/main/ofl/amiri)، [Lato](https://github.com/google/fonts/tree/main/ofl/lato)، [Rakkas](https://github.com/google/fonts/tree/main/ofl/rakkas)، [El Messiri](https://github.com/google/fonts/tree/main/ofl/elmessiri)، [Pacifico](https://github.com/google/fonts/tree/main/ofl/pacifico)، [Playfair Display](https://github.com/google/fonts/tree/main/ofl/playfairdisplay). لعائلة ثمانية راجع [الموقع الرسمي](https://font.thmanyah.com/) و[الترخيص](https://font.thmanyah.com/licenses). التصميم الأصلي `name-card.svg` مهيأ لمساحة اسم فارغة؛ تفعيل منتج آخر لا يضيف إليه زخارف المتجر.
