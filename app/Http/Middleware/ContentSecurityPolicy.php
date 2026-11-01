<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps pages from loading or sending anything to other sites. Even if model output slipped some
 * markup through, the browser would refuse to fetch an outside image, script, font or URL.
 *
 * Alpine (bundled with Livewire) evaluates its expressions with Function(), hence 'unsafe-eval';
 * inline scripts must carry this response's nonce, which Livewire and Vite add for us.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Vite::isRunningHot()) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
