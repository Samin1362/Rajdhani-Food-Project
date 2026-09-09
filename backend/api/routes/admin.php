<?php

declare(strict_types=1);

/**
 * /admin/* — sections 9.8 to 9.11. Bearer token plus a role check per route
 * against the section 7.3 matrix.
 *
 * Role is the only dimension of authorisation (doc 7.4) — there is no second
 * brand-access check. Populated from RTPP-13 onward.
 */

/** @var \Rajdhani\Http\Router $router */

$router->group('/admin', [], static function (\Rajdhani\Http\Router $r): void {
    // RTPP-14 — site profile
    // $r->get('/site-profile',   [SiteProfileController::class, 'show']);
    // $r->patch('/site-profile', [SiteProfileController::class, 'update']);
});
