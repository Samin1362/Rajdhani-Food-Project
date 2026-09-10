# Rajdhani Tea Platform — Backend API

PHP 8.3 REST API over MySQL 8, deployed to shared cPanel hosting.

**Requirements:** `../../Rajdhani_Project_Doc.md` v3.0 — the source of truth.
**Plan:** `../../plan.md` v3.1 (backend only).

This is the API only. The customer site and admin dashboard are separate React +
Vite applications built by another developer; §9 of the requirements document is
the contract between us.

---

## Local setup

Two things differ from a stock machine and both are deliberate.

**PHP 8.3 is not on `PATH`.** It is installed keg-only via Homebrew so it cannot
shadow a system PHP:

```bash
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"
```

**MySQL 8 runs on port 3307, not 3306.** This machine already runs MySQL 9.6 on
the default port. The production target is MySQL 8 (or MariaDB 10.6+), and
authoring a schema against 9.x risks 9-only syntax reaching a host that cannot
run it. So 8.0 gets its own datadir and port, and 9.6 is left alone:

```bash
./bin/mysql8.sh start     # start on :3307
./bin/mysql8.sh status
./bin/mysql8.sh cli       # mysql shell
./bin/mysql8.sh stop
```

Then:

```bash
composer install
cp .env.example .env      # fill in DB_DATABASE and the JWT secrets
./bin/mysql8.sh cli -e "CREATE DATABASE rajdhani_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php bin/migrate.php up
php bin/seed.php          # prints the Super Admin invite token — keep it
php -S 127.0.0.1:8000 -t public public/index.php
```

```bash
curl http://127.0.0.1:8000/api/v1/health
curl http://127.0.0.1:8000/api/v1/health/db
```

---

## Commands

| Command | Does |
|---|---|
| `composer check` | style, static analysis and tests — what CI runs |
| `composer test` | PHPUnit |
| `composer stan` | PHPStan level 8 |
| `composer lint` | php-cs-fixer, dry run |
| `composer fix` | php-cs-fixer, applied |
| `php bin/migrate.php status` | applied and pending migrations |
| `php bin/migrate.php up` | apply everything pending |
| `php bin/migrate.php verify` | confirm applied files match their checksums |
| `php bin/seed.php` | fill an empty database; safe to re-run |
| `php bin/seed.php --list` | the seeders and the order they run in |
| `php bin/seed.php --only=NewsSeeder` | run one seeder |
| `php bin/openapi.php check` | confirm the spec matches the routes |
| `php bin/openapi.php routes` | print the routing table |
| `./vendor/bin/phpunit --testsuite Unit` | unit tests, no database needed |
| `./vendor/bin/phpunit --testsuite Feature` | database-backed tests (skipped if no database) |

---

## Migrations

Forward-only (doc §16.4). The runner records a sha256 of every file as applied
and **refuses to run if an applied file later differs** — an applied migration is
immutable, and a change to one means production and this repository have silently
diverged. To change the schema, add a new migration.

The seven schema files are **generated from §8 of the requirements document**, in
foreign-key dependency order computed from the DDL itself. Two consequences worth
knowing:

- §8's presentation order is *not* apply order. `site_profile` is documented
  first but must be created 32nd, because it references `media_assets`.
- If §8 changes, regenerate rather than hand-editing, so the schema and the
  document cannot drift apart.

---

## Seeding

`php bin/seed.php` brings an empty database to a usable state: the site profile
the header reads, the 64 districts the dealer form offers, a Super Admin to log
in as, and enough demo content that every public endpoint returns something.

**Running it twice changes nothing.** The report prints inserted / updated /
unchanged per table, so "nothing happened" is visible rather than assumed, and
CI fails the build if a second run is not a no-op. The whole run is one
transaction — a seeder that fails half way writes nothing at all.

Which write strategy a seeder uses is a decision about who owns the data:

| Strategy | Used for | On a second run |
|---|---|---|
| `upsert()` | reference data this project owns — districts, upazilas | **corrects** the row; a fix to the JSON reaches an existing database |
| `insertIfAbsent()` | anything the client owns once it exists — site profile, products, copy | **leaves it alone**; never overwrites what an editor typed |

That distinction is the reason seeding is safe to run against staging. Getting
it the wrong way round would put placeholder copy back over the client's content
every deploy.

Three things worth knowing:

- **The Super Admin is seeded without a password.** `admin_users.password_hash`
  is nullable precisely so the account can exist unclaimed: it holds an invite
  token, and login must reject it until the invite is accepted (doc §7.2).
  Seeding a default password instead would leave a working `admin` / `admin123`
  on a public host. The token is printed by the runner because SMTP is not
  configured yet (RTPP-17).
- **The media rows are placeholders, not uploads.** Cloudinary has no
  credentials yet, so `media_assets` gets rows under a `rajdhani/demo/` prefix
  pointing at a placeholder image service. They are replaced under RTPP-86, and
  the prefix makes them trivial to find and delete.
- **The copy is placeholder** (doc §19), including the upazila spellings, which
  drive a public dropdown and have not been confirmed by the client (RTPP-17).

Source data lives in `database/seed-data/` — inside `backend/api` rather than at
the project root, so the seeders still work from the deployed directory.

---

## Authentication

Two separate systems share this backend (doc §7). They differ in how a caller
proves who they are; everything after that — rotation, revocation, cookies — is
shared. **An admin token is never valid on a customer route**, enforced by the
`aud` claim inside `JwtHelper::decode()` rather than by each middleware, so no
future route can forget the check.

| | Access token | Refresh token |
|---|---|---|
| What it is | HS256 JWT, `sub` + `aud` + `role` | 32 random bytes; the database row is the authority |
| Lifetime | 20 minutes (admin) | 7 days (admin) |
| Carried in | `Authorization: Bearer`, held in memory | `HttpOnly` cookie, path-scoped to `/api/v1/auth` |
| Revocable | no — it expires | yes, and that is why it is not a JWT |

**Rotation and breach detection.** Every refresh spends the presented token and
issues a new one carrying the same `family_id`. A token that has already been
spent arriving again means two parties hold it, and there is no way to tell the
thief from the victim — so the whole family is revoked and both are forced back
to a password login, which the attacker cannot complete.

**The seeded Super Admin has no password.** It holds an invite token
(`bin/seed.php` prints it) and cannot be logged into until
`POST /auth/admin/invite/:token/accept` sets one. Login rejects a null
`password_hash` explicitly, not merely as a side effect of the hash check.

**Login throttle** (doc §7.2, §14.2): five failures per email per fifteen
minutes, then a thirty-minute lockout, counted from `login_attempts`. A looser
per-IP limit runs alongside it to catch password spraying across many accounts —
looser because an office behind one NAT address is a legitimate reason for
several admins to fail at once.

**Every authentication failure gives the same answer.** Wrong password, no such
account, deactivated, never claimed — one message, one status, and
`PasswordHelper::verify()` burns equivalent work against a decoy hash when there
is no account, so the response time does not give it away either.

## API reference

`docs/openapi.yaml` — OpenAPI 3.1, covering every implemented route with request
and response schemas, both auth schemes, and the §9.1 envelope. Import it into
Postman or Insomnia rather than reading it.

Rendered at **`/api/v1/docs`**. Open outside production; in production it needs
`?token=` matching `DOCS_TOKEN`, and returns **404** without one — a 401 would
confirm the endpoint is there. **No `DOCS_TOKEN` means closed**, not open, so a
deployment that never read this file does not publish its own attack surface.

**It is maintained by hand and therefore drifts** — there is no swagger-jsdoc for
PHP without an annotation library and a build step, and this project deploys by
uploading files. So `php bin/openapi.php check` compares the spec against the
live routing table **in both directions**: a route with no entry, or an entry
with no route, fails. It runs in `composer check` and in CI, so drift breaks the
build on the commit that caused it.

That is not theatre — it caught the `/docs` routes themselves within a minute of
my adding them.

`symfony/yaml` parses the spec for that check and is a **dev dependency only**;
production never loads it, and the docs endpoint serves the file as raw YAML
rather than converting it.

---

## Rate limiting and transport security

§14.2 asks for three per-IP limits. Two are here; the third — admin login, five
failures per email then a lockout — lives in `AdminAuthService` because it counts
*failures* rather than requests.

| scope | limit | where |
|---|---|---|
| global | 100 / 15 min | `RateLimit`, global middleware |
| public form | 5 / hour, per form | `ThrottleForm('enquiry')`, per route |

**The counter is a database table, and that is the whole point.** PHP-FPM hands
each request to whichever worker is free, and those workers share nothing — an
in-memory counter sees a fraction of the traffic and the limit silently never
fires. This is the piece the Node → PHP change hurt most (doc §19, deviation 2);
`express-rate-limit` had one long-lived process to count in, and there is no such
process here.

`rate_limits` did not exist in §8 — the document required database-backed limits
without defining the storage. Added as migration 008 and recorded as deviation 7.

Two exemptions, both deliberate: **`OPTIONS`**, because a preflight is the
browser asking permission and counting it would halve every cross-origin
client's budget; and **the health endpoints**, because an uptime monitor polls
them on a schedule and throttling it produces exactly the alert it exists to
avoid.

**The limiter fails open.** If the counter is unreachable the request proceeds. A
guard rail that becomes a wall when it breaks is worse than the thing it guards
against.

Every response carries `X-RateLimit-Limit`, `-Remaining` and `-Reset`, and a 429
adds `Retry-After`. All four are in the CORS exposed-headers list, or the browser
hides them from JavaScript and a client cannot slow down before it is refused.

### The other guards

**`GuardQueryParameters`** rejects array-valued query parameters. PHP turns
`?status[]=A&status[]=B` into an array where every caller expects a string —
`(string) $array` emits "Array", `strlen()` throws — and none of it is visible to
a reviewer reading `$request->query('status')`. No endpoint takes an array today,
so rejecting them outright is correct now and will need relaxing per-route the
day one genuinely wants one.

**HTTPS is refused, not redirected**, in production. A 301 on a POST drops the
body in some clients, and by the time the redirect is issued the credentials in
that request have already crossed the network in clear text. The front-ends
redirect in their own `.htaccess`, which is where a browser-facing redirect
belongs.

**The API's CSP is `default-src 'none'`** and that is not an oversight. §14.2's
permissive policy — Cloudinary, Google Fonts, Maps, Identity — describes what a
*browser* loads while rendering a page, and this API returns JSON. That policy
ships as **`deploy/frontend.htaccess`**, ready to drop into both front-end
document roots. Give it to the front-end developer.

### One thing found while building this

MySQL's `NOW()` was six hours ahead of the application's clock: the app writes
UTC strings built in PHP, and the MySQL session inherited the machine's
Asia/Dhaka timezone. Nothing was broken, because the application never mixed the
two — but any query comparing a stored timestamp against `NOW()` would have been
silently wrong. `Database::connection()` now pins the session to `+00:00`.

---

## The site profile

`site_profile` is a singleton pinned by `CHECK (id = 1)` — the row that replaced
the `brands` table when the project dropped to one site (doc §6). Everything the
header, footer, theme and contact page need lives in it.

`GET /public/layout` composes it with the navigation into the one call the
front-end boots from: site identity, logos, theme colours, contact block, map,
footer copy, grouped menus, social links, newsletter visibility. **No
authentication, no header, no query string** — under v2.0 this is where
`X-Brand` would have gone, and its absence is the observable part of the
single-site cut.

Three things that are deliberate:

- **Nothing inserts.** The row is created once by the seeder; every later change
  is an `UPDATE … WHERE id = 1`. The CHECK constraint is the backstop for a
  mistake this code does not make, not the mechanism.
- **The theme is data, never constants.** §18.2 requires the client to change
  colours from the admin panel with no deployment, so the hex values travel in
  this payload and the front-end applies them as CSS custom properties. A
  hard-coded colour anywhere in the stack breaks that.
- **Optional fields come back as `null`, not as zero.** Coordinates especially:
  `(0, 0)` is a real place in the Gulf of Guinea, and a map centred there is
  worse than one the front-end knows to hide.

Images are returned as `{id, url, alt}` — the public site needs a URL to render,
the admin panel needs the id to change it, and returning one would force the
other consumer into a second request.

`PATCH /admin/site-profile` writes through an allowlist with per-field
validation (hex colours, email addresses, coordinate ranges), and is
Super-Admin-only via `RequireRole::write(Capability::SETTINGS)`.

---

### Authorisation — the §7.3 matrix

**Role is the only dimension** (doc §7.4). Under v2.0 access was the
intersection of role and brand; with one site the intersection is just the role,
so `RequireBrandAccess` and `admin_brand_access` are gone.

Every admin route carries two middleware, in this order:

```php
[RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)]
```

`RequireAdmin` establishes *who*; `RequireRole` decides *what*, against
`RolePolicy` — the §7.3 table written out as data. A route registered with
`RequireAdmin` alone is reachable by every admin of every role, which is correct
for `/auth/admin/me` and almost nothing else.

Four access levels, totally ordered: `NONE < READ < OWN < WRITE`. **A capability
missing from a role's row is NONE** — deny is the default, so adding a
capability without deciding its permissions locks everyone out rather than
letting everyone in.

`OWN` exists for exactly one cell: an Editor may delete media they uploaded, not
media somebody else did. That is a row-level rule a middleware cannot decide, so
`RequireRole::own()` admits the request and records `row_scope` (`all` or `own`)
on it — **and the service is then obliged to filter**. Using `own()` anywhere
else means inventing a rule the document does not contain.

`GET /auth/admin/me` returns the caller's whole matrix row as `permissions`,
served from the same constant the middleware enforces. §7.3 says the dashboard
hides unavailable navigation but the API is the source of truth; sending the row
is what stops a hidden button and a 403 from disagreeing. It is a convenience
for the UI, never a substitute for the server-side check.

### Customer sign-in

Customers use Google only — no password, and no registration endpoint: the
account is created by the first successful sign-in. The browser gets an ID token
from Google Identity Services and posts it to `POST /auth/customer/google`.

**That token is attacker-controlled input** until every check in
`GoogleIdTokenVerifier` has passed. Each one maps to an attack:

| Check | Without it |
|---|---|
| RS256 signature against Google's JWKS | anyone can write their own token |
| `alg` from our list, not the token's | `alg: none`, and HS256 confusion |
| `iss` is Google | a token from any other issuer |
| **`aud` is our client id** | **a real Google token minted for someone else's app** |
| `exp` / `iat` | replay of an old token |
| `email_verified` | claiming an address you do not own |

The `aud` check is the one that gets left out, and it is the one that would turn
every other Google-enabled site's login into ours.

**Account resolution order is load-bearing**: `google_id` first, then `email`,
then create. Matching on email at all is only safe because an unverified address
was already rejected; reversing the two would hand an account to whoever last
used the address rather than to the Google account that owns it.

Google's signing keys are cached on disk with the TTL from their own
`Cache-Control` header (186 ms → 0.1 ms). A key id that is not in the cache
triggers exactly one forced refetch before the token is rejected — an unknown
`kid` is far more often a rotation than a forgery.

**Local development caveat.** `SameSite=None` requires `Secure`, which requires
HTTPS. Over `http://localhost` the cookie would be dropped, so `Cookie::secure()`
falls back to `SameSite=Lax` when `APP_URL` is not https. Cross-origin refresh
therefore cannot be exercised locally — that is a staging check (RTPP-82). Also
leave `COOKIE_DOMAIN` empty locally: a `.rajdhanifood.com` value means the
browser discards the cookie on `127.0.0.1`.

---

## Layout

Only `public/` is web-exposed. Everything else sits above it and is unreachable
over HTTP, enforced by pointing the domain's document root at `public/`. Where a
host will not allow that, the `.htaccess` deny at the project root is the weaker
fallback and must be security-reviewed (doc §16.2 item 4).

```
app/
  Controllers/   HTTP only: parse, delegate, respond
  Services/      business rules; the only caller of repositories
  Repositories/  the only layer that issues SQL
  Middleware/    Cors, SecurityHeaders, auth, roles, validation, rate limit, audit
  Helpers/       ApiResponse, ApiError, ErrorCode, Pagination, SlugHelper, UlidHelper
  Support/       Env, Logger, Database
  Http/          Request, Router
  Kernel.php     boot, dispatch, and the only place errors become responses
```

**Layering is a rule, not a style.** A controller never touches a repository; a
service never touches `$_REQUEST`. A service that reaches for a superglobal
cannot be unit-tested and cannot be reused from a cron job.

---

## Conventions

- Every response is the §9.1 envelope: `{ success, data, meta }` or
  `{ success, error: { code, message, details } }`. `ApiResponse` is the only
  thing that writes a body.
- Error codes are a closed set (`ErrorCode`). The front-end switches on
  `error.code`, so adding one is a contract change and belongs in the document
  first.
- Primary keys are ULIDs in `CHAR(26)`, generated in PHP. The one exception is
  `site_profile`, a `TINYINT` singleton pinned by `CHECK (id = 1)`.
- All SQL goes through PDO prepared statements, inside a repository. No string
  interpolation into a query, anywhere.
- Anything thrown that is not an `ApiError` is a bug: it is logged with its trace
  and answered with a generic `INTERNAL_ERROR`. With `APP_DEBUG=false` no
  message, path or query ever reaches a client.

---

## Not yet done in Phase 1

RTPP-90 (cPanel prerequisites — **unconfirmed**) — the last Phase 1 item, and
it needs the hosting account rather than code.
