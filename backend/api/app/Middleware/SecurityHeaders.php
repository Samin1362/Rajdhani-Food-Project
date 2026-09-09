<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Helpers\ApiResponse;
use Rajdhani\Http\Request;

/**
 * Transport-level hardening from section 14.2.
 *
 * This is a JSON API, so the CSP here is the restrictive one appropriate to
 * responses that are never rendered as a document. The permissive CSP in
 * section 14.2 — the one allowing Cloudinary, Google Fonts, Maps and Identity —
 * belongs to the front-ends' own .htaccess, because those are the origins that
 * actually load third-party assets.
 */
final class SecurityHeaders implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        ApiResponse::header('X-Content-Type-Options', 'nosniff');
        ApiResponse::header('X-Frame-Options', 'DENY');
        ApiResponse::header('Referrer-Policy', 'no-referrer');
        ApiResponse::header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        // HSTS only over TLS. Sending it on a plaintext local request would pin
        // localhost to HTTPS in the developer's browser.
        if ($this->isSecure()) {
            ApiResponse::header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $next($request);
    }

    private function isSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off') {
            return true;
        }

        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
