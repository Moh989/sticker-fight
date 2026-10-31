# تشغيل نسخة Sticker Fight المعتمدة

النسخة المحلية على http://localhost:8002، وقاعدة بياناتها sticker_fight_newdes. إعداداتها وAPP_KEY وحساب الإدارة مستقلان؛ بيانات الإدارة المحلية في .runtime/admin-access.txt ولا تُنشر. البداية الكاملة في README.md.

عند النشر من GitHub حدّد `NEWDES` مجلداً للتطبيق و`NEWDES/public` جذر الويب. شغّل Composer وnpm وArtisan وعامل Queue من داخل NEWDES. مثال Supervisor في docs/deployment/worker.conf.example، وNginx في nginx.conf.example. غيّر المسار ومستخدم التشغيل وPHP-FPM وفق خادمك.

Scheduler: أضف cron كل دقيقة من داخل مسار النسخة الصحيحة:

```cron
* * * * * cd /var/www/sticker-fight-newdes && php artisan schedule:run >/dev/null 2>&1
```

النسخ الاحتياطي والحذف متوقفان افتراضياً. اضبط BACKUP_PASSWORD ووجهة نسخ خارج الخادم، واختبر الاستعادة قبل تفعيل BACKUP_ENABLED. أمر التحقق هو `php artisan sticker:backup-verify /path/to/archive`. مرفقات التسعير لا تدخل في حذف المسودات غير المرجعية.

ارفع حدود PHP إلى upload_max_filesize=14M وpost_max_size=18M وحد طلب الخادم إلى 18M أو أكثر. حد التطبيق للكتالوج 4 MiB وللمحرر ومرفقات عروض السعر 14 MiB. لا يكفي تعديل JavaScript لتغيير حد الاستضافة.

في الإنتاج استخدم قاعدة نظيفة وإعدادات المالك، STORE_DEMO_MODE=false، APP_DEBUG=false، APP_URL بنظام HTTPS وSESSION_SECURE_COOKIE=true. لا تنشر .env أو .runtime أو قاعدة العرض أو ملفاتها الخاصة. لا تغيّر APP_KEY بعد بدء حفظ البيانات.

عائلة ثمانية ليست ضمن ملفات GitHub العامة. من تنزيل المالك الرسمي انقل ملفات `thmanyahsans-Regular.woff2` و`thmanyahserifdisplay-Regular.woff2` و`thmanyahseriftext-Regular.woff2` إلى `storage/app/private/design-fonts` على الاستضافة مع ترخيصها. لا تنشئ رابطاً عاماً لها. إلى أن تثبت الملفات تختفي هذه الاختيارات فقط، وتبقى الخطوط المفتوحة متاحة؛ عينات القائمة PNG عامة ولا تحتوي برنامج الخط. يلزم محرك Chromium الموجود أصلاً للتصدير لرسم معاينة أسماء ثمانية أيضاً. أعد تشغيل عامل Queue بعد تحديث الكود.

كتالوج النسخة الحالية مرفق بإصدار GitHub `newdes-2026-10-31`؛ من داخل NEWDES شغّل `php -d memory_limit=512M artisan sticker:catalog-restore --no-interaction` بعد migrations. يتطلب التنزيل نحو ٢ غيغابايت ومساحة مؤقتة تقارب ٧ غيغابايت. الاستيراد يحافظ على المنتجات الموجودة وتعديلات الإدارة ولا ينقل الزبائن أو الطلبات أو الخطوط الخاصة أو إعدادات التحصيل والشحن. التفاصيل في README. لا تستخدم حزمة CUCU القديمة بدلاً من حزمة NEWDES إذا أردت نقل التصنيفات والأسعار الخاصة وترتيب المميز وإعدادات الاسم الحالية.
