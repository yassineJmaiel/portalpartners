<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/* The portal's pages: same-origin scripts only, and the QR script from a pinned CDN.
   Documents set their own, stricter policy. */
class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self' https://unpkg.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self' data: https://cdn.jsdelivr.net https://tessdata.projectnaptha.com; worker-src 'self' blob: https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; frame-src 'self' blob:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

    public const DOC_CSP = "default-src 'none'; script-src 'unsafe-inline' https://unpkg.com; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src data: https:; frame-ancestors 'self'";

    public function handle(Request $request, Closure $next)
    {
        $res = $next($request);
        $res->headers->set('X-Content-Type-Options', 'nosniff');
        $res->headers->set('Referrer-Policy', 'same-origin');
        $res->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        if (! $res->headers->has('Content-Security-Policy')) {
            $res->headers->set('Content-Security-Policy', self::CSP);
        }
        if (str_contains((string) $res->headers->get('Content-Type'), 'text/html') && ! $res->headers->has('Cache-Control-Set')) {
            $res->headers->set('Cache-Control', 'no-store, private');
        }

        return $res;
    }
}
