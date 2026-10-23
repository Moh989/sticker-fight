<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StaffOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }abort_unless(in_array(auth()->user()->role, ['admin', 'orders', 'production']), 403);

        return $next($request);
    }
}
