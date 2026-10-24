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
        $workspace = hash_hmac('sha256', 'sticker-fight:guest:'.session('guest_id'), (string) config('app.key'));
        $request->attributes->set('guest_workspace', $workspace);
        if ($request->hasHeader('X-Guest-Workspace') && ! hash_equals($workspace, (string) $request->header('X-Guest-Workspace'))) {
            return response()->json([
                'code' => 'guest_workspace_changed',
                'message' => __('تغيّرت جلسة الزائر. افتح المحرر من جديد لمساحتك الحالية. هذا التصميم لم يُنقل إلى الجلسة الجديدة.'),
            ], 409)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return $next($request);
    }
}
