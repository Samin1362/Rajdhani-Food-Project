<?php

declare(strict_types=1);

/**
 * /public/* — sections 9.3 to 9.7. No authentication required; a customer token
 * is optional and only unlocks wishlist, reviews and the account area.
 *
 * There is no brand header and no brand resolution: the API serves one site
 * (doc 6). Populated from RTPP-14 onward.
 */

/** @var \Rajdhani\Http\Router $router */

$router->group('/public', [], static function (\Rajdhani\Http\Router $r): void {
    // RTPP-14 — the site profile the front-end boots from
    // $r->get('/layout', [LayoutController::class, 'show']);
});
