<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecureUrls
{
    /**
     * Keep generated links and internal redirects secure when TLS ends before PHP.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secureUrls = (bool) config('session.secure') || strtolower((string) parse_url((string) config('app.url'), PHP_URL_SCHEME)) === 'https';
        URL::forceScheme($secureUrls ? 'https' : null);

        $response = $next($request);
        if (! $secureUrls || ! $response instanceof RedirectResponse) {
            return $response;
        }

        $target = parse_url($response->getTargetUrl());
        if (! is_array($target) || strtolower($target['scheme'] ?? '') !== 'http' ||
            strcasecmp($target['host'] ?? '', $request->getHost()) !== 0 || isset($target['user']) || isset($target['pass'])) {
            return $response;
        }

        $authority = $target['host'];
        if (isset($target['port']) && $target['port'] !== 80) {
            $authority .= ':'.$target['port'];
        }
        $location = 'https://'.$authority.($target['path'] ?? '');
        if (isset($target['query'])) {
            $location .= '?'.$target['query'];
        }
        if (isset($target['fragment'])) {
            $location .= '#'.$target['fragment'];
        }
        $response->setTargetUrl($location);

        return $response;
    }
}
