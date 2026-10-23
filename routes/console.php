<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sticker:backup')->dailyAt('03:15')->timezone('Asia/Baghdad')->withoutOverlapping()->when(fn () => config('sticker.backup_enabled'));
Schedule::command(config('sticker.purge_orders') ? 'sticker:purge --force --orders' : 'sticker:purge --force')->dailyAt('04:15')->timezone('Asia/Baghdad')->withoutOverlapping()->when(fn () => config('sticker.purge_enabled'));
