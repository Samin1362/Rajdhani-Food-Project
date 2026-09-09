<?php

declare(strict_types=1);

/**
 * /admin/* — sections 9.8 to 9.11.
 *
 * **Every route in this file carries two middleware, in this order:**
 *
 *     [RequireAdmin::class, RequireRole::read|write|own(Capability::…)]
 *
 * RequireAdmin establishes who the caller is; RequireRole decides what they may
 * do against the §7.3 matrix. Role is the only dimension of authorisation
 * (doc §7.4) — there is no brand check any more, and there is no second gate.
 *
 * Pick the level from the verb, not from the resource:
 *
 *     $r->get('/products',      …, [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)]);
 *     $r->post('/products',     …, [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)]);
 *     $r->delete('/media/:id',  …, [RequireAdmin::class, RequireRole::own(Capability::MEDIA)]);
 *
 * A route registered with RequireAdmin alone is **unauthorised by anything but
 * authentication** — every admin, of every role, can reach it. That is correct
 * for `/auth/admin/me` and almost nothing else.
 *
 * `own()` is the exception rather than the pattern: it admits the request and
 * records `row_scope` on it, and the *service* must then filter to rows the
 * caller uploaded. It exists for one cell of §7.3 — an Editor deleting their own
 * media — and using it anywhere else means inventing a rule the document does
 * not contain.
 *
 * Populated from RTPP-14 onward.
 */

use Rajdhani\Auth\Capability;
use Rajdhani\Controllers\Admin\SiteProfileController;
use Rajdhani\Http\Router;
use Rajdhani\Middleware\RequireAdmin;
use Rajdhani\Middleware\RequireRole;

/** @var Router $router */

$router->group('/admin', [], static function (Router $r): void {
    // RTPP-14 — site profile. Settings are Super-Admin-only in §7.3, so this
    // read is deliberately narrower than /public/layout, which serves the same
    // row to everyone: what is gated is the settings screen, not the content.
    $r->get(
        '/site-profile',
        Router::to(SiteProfileController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::SETTINGS)],
    );
    $r->patch(
        '/site-profile',
        Router::to(SiteProfileController::class, 'update'),
        [RequireAdmin::class, RequireRole::write(Capability::SETTINGS)],
    );
});
