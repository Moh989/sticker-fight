<?php

use App\Http\Middleware\EnsureSecureUrls;
use App\Http\Middleware\GuestIdentity;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StaffOnly;
use App\Http\Middleware\StartStoreSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(EnsureSecureUrls::class);
        $middleware->encryptCookies(except: ['sf_theme']);
        $middleware->web(replace: [StartSession::class => StartStoreSession::class], append: [GuestIdentity::class, SecurityHeaders::class]);
        $middleware->alias(['staff' => StaffOnly::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
