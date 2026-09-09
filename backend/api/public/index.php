<?php

declare(strict_types=1);

/**
 * The single front controller (doc 13).
 *
 * This directory is the only one the web server exposes. Everything else —
 * app/, config/, database/, storage/, vendor/, .env — sits one level up and is
 * unreachable over HTTP, enforced by pointing the domain's document root here.
 * Where a host will not allow that, the .htaccess deny at the project root is
 * the weaker fallback and has to be security-reviewed (doc 16.2 item 4).
 */

use Rajdhani\Kernel;
use Rajdhani\Middleware\Cors;
use Rajdhani\Middleware\SecurityHeaders;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

Kernel::boot($basePath);

/** @var \Rajdhani\Http\Router $router */
$router = require $basePath . '/routes/api.php';

Kernel::handle($router, [
    // Order matters. CORS answers preflight before anything else can reject the
    // request, and security headers are set before a handler can emit a body.
    Cors::class,
    SecurityHeaders::class,
]);
