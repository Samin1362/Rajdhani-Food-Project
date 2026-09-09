<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

/**
 * Section 4.1. All three tiers are separate origins, so credentials must be
 * allowed for refresh cookies to flow — which in turn means the allowlist has to
 * be exact. `Access-Control-Allow-Origin: *` is invalid alongside credentials,
 * and the browser will reject it.
 */
return [
    'origins' => Env::list('CORS_ORIGINS'),

    'methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],

    'exposed_headers' => ['X-Request-Id'],

    'credentials' => true,

    'max_age' => 86400,

    'cookie_domain' => Env::get('COOKIE_DOMAIN'),
];
