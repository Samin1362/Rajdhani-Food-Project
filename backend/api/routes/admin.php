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
use Rajdhani\Controllers\Admin\CategoryController;
use Rajdhani\Controllers\Admin\ProductController;
use Rajdhani\Controllers\Admin\ProductHighlightController;
use Rajdhani\Controllers\Admin\ProductImageController;
use Rajdhani\Controllers\Admin\ProductPackSizeController;
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

    // RTPP-18 — categories. Editor and Super Admin both hold WRITE on
    // PRODUCTS (§7.3); Sales holds only READ, so /categories is visible in
    // the dashboard but its mutating routes are not. /reorder is a write:
    // reordering changes what the public site shows.
    $r->get(
        '/categories',
        Router::to(CategoryController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->get(
        '/categories/:id',
        Router::to(CategoryController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/categories',
        Router::to(CategoryController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->patch(
        '/categories/reorder',
        Router::to(CategoryController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->patch(
        '/categories/:id',
        Router::to(CategoryController::class, 'update'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->delete(
        '/categories/:id',
        Router::to(CategoryController::class, 'destroy'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );

    // RTPP-19 — products, and the three child collections that make up the
    // tabbed admin product form. Same role gate as categories: Editor and
    // Super Admin hold WRITE on PRODUCTS, Sales holds only READ (§7.3).
    //
    // Every /reorder route is registered before its sibling /:id route for
    // the same HTTP method — the router matches in registration order, and
    // :id would otherwise capture the literal segment "reorder" as an id.
    $r->get(
        '/products',
        Router::to(ProductController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/products',
        Router::to(ProductController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/reorder',
        Router::to(ProductController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->get(
        '/products/:id',
        Router::to(ProductController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id',
        Router::to(ProductController::class, 'update'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->delete(
        '/products/:id',
        Router::to(ProductController::class, 'destroy'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );

    // Pack sizes
    $r->get(
        '/products/:id/pack-sizes',
        Router::to(ProductPackSizeController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/products/:id/pack-sizes',
        Router::to(ProductPackSizeController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/pack-sizes/reorder',
        Router::to(ProductPackSizeController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->get(
        '/products/:id/pack-sizes/:packSizeId',
        Router::to(ProductPackSizeController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/pack-sizes/:packSizeId',
        Router::to(ProductPackSizeController::class, 'update'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->delete(
        '/products/:id/pack-sizes/:packSizeId',
        Router::to(ProductPackSizeController::class, 'destroy'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );

    // Highlights
    $r->get(
        '/products/:id/highlights',
        Router::to(ProductHighlightController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/products/:id/highlights',
        Router::to(ProductHighlightController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/highlights/reorder',
        Router::to(ProductHighlightController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->get(
        '/products/:id/highlights/:highlightId',
        Router::to(ProductHighlightController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/highlights/:highlightId',
        Router::to(ProductHighlightController::class, 'update'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->delete(
        '/products/:id/highlights/:highlightId',
        Router::to(ProductHighlightController::class, 'destroy'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );

    // Images
    $r->get(
        '/products/:id/images',
        Router::to(ProductImageController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/products/:id/images',
        Router::to(ProductImageController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/images/reorder',
        Router::to(ProductImageController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->get(
        '/products/:id/images/:imageId',
        Router::to(ProductImageController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/images/:imageId',
        Router::to(ProductImageController::class, 'update'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
    $r->delete(
        '/products/:id/images/:imageId',
        Router::to(ProductImageController::class, 'destroy'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)],
    );
});
