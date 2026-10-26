<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class StaffOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }abort_unless(in_array(auth()->user()->role, User::STAFF_ROLES, true), 403);

        return $next($request);
    }
}
