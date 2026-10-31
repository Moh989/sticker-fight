<?php

namespace App\Http\Middleware;

use App\Services\StoreSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $imageSources = ["'self'", 'data:', 'blob:'];
        $mediaSources = ["'self'"];
        if ($request->is('installation')) {
            foreach (StoreSettings::get('installation_steps', []) as $step) {
                $origin = $this->httpsOrigin($step['image_url'] ?? null);
                if ($origin) {
                    $imageSources[] = $origin;
                }
            }
            $origin = $this->httpsOrigin(StoreSettings::get('installation_video'));
            if ($origin) {
                $mediaSources[] = $origin;
            }
        }
        $images = implode(' ', array_unique($imageSources));
        $media = implode(' ', array_unique($mediaSources));
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src {$images}; media-src {$media}; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        if (($request->is('products/*') && $request->filled('cart')) || $request->is('api/*', 'editor*', 'saved*', 'admin*', 'orders*', 'restore*', 'media*', 'cart*', 'checkout*', 'account*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    private function httpsOrigin(?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ! preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/i', $parts['host'] ?? '')) {
            return null;
        }

        return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
