<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        foreach (['api' => 90, 'tracking' => 30, 'restore' => 10, 'uploads' => 15, 'login' => 5, 'register' => 3, 'review' => 3, 'checkout' => 5] as $key => $limit) {
            RateLimiter::for('sf-'.$key, fn ($request) => Limit::perMinute($limit)->by('sf-'.$key.':'.$request->ip()));
        }

        Paginator::defaultView('components.pagination');
        View::composer(['components.layout', 'components.admin-layout'], function ($view) {
            $view->with('storeSettings', Setting::all()->pluck('value', 'key')->all());
            $view->with('appearanceTheme', request()->cookie('sf_theme') === 'light' ? 'light' : 'dark');
        });
    }
}
