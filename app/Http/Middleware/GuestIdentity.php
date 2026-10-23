<?php

namespace App\Http\Middleware;

use App\Models\GuestSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GuestIdentity
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale(in_array(session('locale'), ['ar', 'en']) ? session('locale') : 'ar');
        if (! session('guest_id') || ! GuestSession::find(session('guest_id'))) {
            $token = bin2hex(random_bytes(32));
            $guest = GuestSession::create(['id' => (string) Str::uuid(), 'recovery_hash' => hash('sha256', $token), 'last_seen_at' => now()]);
            session(['guest_id' => $guest->id, 'recovery_token' => $token]);
        }

        return $next($request);
    }
}
