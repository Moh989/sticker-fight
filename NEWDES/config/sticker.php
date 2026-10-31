<?php

return [
    'backup_retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'backup_password' => env('BACKUP_PASSWORD'),
    'backup_enabled' => (bool) env('BACKUP_ENABLED', false),
    'purge_orders' => (bool) env('PURGE_ORDER_FILES', false),
    'purge_enabled' => (bool) env('PURGE_ENABLED', false),
    'mysqldump' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'node' => env('NODE_BINARY', 'node'),
    'chromium' => env('CHROMIUM_EXECUTABLE'),
    'ppi' => (int) env('EXPORT_PPI', 300),
    'max_upload_mb' => 14,
    'max_catalog_upload_mb' => 4,
    'max_pixels' => 32000000,
    'retention_days' => (int) env('DESIGN_RETENTION_DAYS', 90),
    'order_retention_days' => (int) env('ORDER_RETENTION_DAYS', 730),
    'track_cart_additions' => (bool) env('TRACK_CART_ADDITIONS', ! in_array(env('APP_ENV'), ['testing', 'qa'])),
    'demo_mode' => (bool) env('STORE_DEMO_MODE', false),
    'colors' => ['background' => '#FAF9F6', 'surface' => '#F6EEF1', 'primary' => '#AB4E70', 'text' => '#30262B', 'border' => '#E8DCE1'],
    'statuses' => ['new' => 'جديد', 'pending_confirmation' => 'بانتظار التأكيد', 'design_review' => 'يحتاج مراجعة تصميم', 'approved' => 'معتمد للإنتاج', 'printing' => 'قيد الطباعة', 'ready_to_ship' => 'جاهز للشحن', 'shipped' => 'تم الشحن', 'delivered' => 'تم التسليم', 'cancelled' => 'ملغي', 'returned' => 'مرتجع'],
];
