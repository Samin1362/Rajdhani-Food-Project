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

RTPP-90 (cPanel prerequisites — **unconfirmed**), RTPP-12 (customer auth),
RTPP-13 (roles — `RequireRole` and the §7.3 matrix), RTPP-14
(`/public/layout`), RTPP-15 (rate limiting beyond admin login — CORS and
security headers are in), RTPP-16 (OpenAPI).
