<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);

        $returnTo = $request->query('return_to', '/');
        if (! is_string($returnTo)) {
            $returnTo = '/';
        }
        $decoded = rawurldecode($returnTo);
        if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//') ||
            str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded) ||
            preg_match('#^/language(?:/|\\?|$)#', $decoded)) {
            $returnTo = '/';
        }

        $request->session()->put('locale', $locale);

        return redirect()->to($returnTo)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
