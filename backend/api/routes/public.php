<?php

declare(strict_types=1);

/**
 * /public/* — sections 9.3 to 9.7. No authentication required; a customer token
 * is optional and only unlocks wishlist, reviews and the account area.
 *
 * There is no brand header and no brand resolution: the API serves one site
 * (doc §6). Populated from RTPP-14 onward.
 */

use Rajdhani\Controllers\Public\LayoutController;
use Rajdhani\Http\Router;

/** @var Router $router */

$router->group('/public', [], static function (Router $r): void {
    // RTPP-14 — the site profile the front-end boots from. Deliberately
    // unauthenticated and header-free: it is the first call a cold page load
    // makes, before anyone has signed in.
    $r->get('/layout', Router::to(LayoutController::class, 'show'));
});
