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
    'max_upload_mb' => 12,
    'max_pixels' => 32000000,
    'retention_days' => (int) env('DESIGN_RETENTION_DAYS', 90),
    'order_retention_days' => (int) env('ORDER_RETENTION_DAYS', 730),
    'colors' => ['black' => '#0A0A0D', 'charcoal' => '#18181E', 'pink' => '#FF2D8D', 'pinkHover' => '#FF78B5', 'white' => '#F7F5F8', 'muted' => '#B8B4BE', 'border' => '#34343E'],
    'statuses' => ['new' => 'جديد', 'pending_confirmation' => 'بانتظار التأكيد', 'design_review' => 'يحتاج مراجعة تصميم', 'approved' => 'معتمد للإنتاج', 'printing' => 'قيد الطباعة', 'ready_to_ship' => 'جاهز للشحن', 'shipped' => 'تم الشحن', 'delivered' => 'تم التسليم', 'cancelled' => 'ملغي', 'returned' => 'مرتجع'],
];
