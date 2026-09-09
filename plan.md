# Rajdhani Tea Platform — Backend Development Plan

**v3.1 — backend only · PHP 8.3 · MySQL 8 · single site · shared cPanel hosting**

Build plan for the **backend API only**, mapped onto the Jira backlog in project **RTPP**
on `saminisrak1991.atlassian.net`.

**Source of truth for requirements:** `Rajdhani_Project_Doc.md` v3.0 (also published as a
page tree in the RTPP Confluence space). Section references below use `§n` from that
document. Where this plan and the document disagree, the document wins and this file is
wrong.

> **The requirements document stays full-scope.** It describes the whole platform —
> backend, admin dashboard and customer site — because the front-end developer needs
> §5.1, §5.2, §10 and §11 to do their work. Only *this plan* narrows to the backend.

> **Superseded plans**, kept alongside this file: `plan.v3-fullstack.md.bak` (all six
> phases, both front-ends), `plan.v2-multibrand.md.bak` (two brands), and
> `plan.v1-node-stack.md.bak` (Node / Express / Prisma / PostgreSQL).

---

## 1. Scope

**I build the API. Someone else builds the two front-ends.** Split confirmed 2026-09-08.

| Mine | Jira | Issues | Points |
|---|---|---|---|
| Phase 1 — Foundation | RTPP-1 | 11 *(+RTPP-17, parallel)* | 43 |
| Phase 2 — Core API | RTPP-2 | 19 | 83 |
| Phase 5 — backend slice | RTPP-5 | 2 | 13 |
| Phase 6 — backend & devops slice | RTPP-6 | 5 | 19 |

**37 issues, 158 points.** Phases 1 and 2 — the whole API surface — are **4.5 weeks**.

### Not mine

Left in Jira for the front-end developer to be assigned, **not deleted**:

| Epic | Phase | Issues |
|---|---|---|
| RTPP-3 | Admin Dashboard | RTPP-37 … RTPP-55 (19) |
| RTPP-4 | Customer Site | RTPP-56 … RTPP-72 (17) |
| RTPP-5 | Responsive QA, accessibility, front-end performance | RTPP-76, 77, 78 |

Labels already separate them: `backend` vs `frontend-web` / `frontend-admin`.

**Shared at launch (RTPP-6):** UAT (85), content entry (86), DNS cutover (87), handover
(88) and sign-off (89) need both sides. I own the infrastructure half.

### One boundary case worth naming

**RTPP-73, the PHP shell renderer (§14.3), is mine** even though it exists to serve the
customer site. It is PHP, it runs on the API's origin, and it reads `seo_meta` directly —
it is backend work by every practical measure. But it consumes the front-end's built
`index.html` and must inject into it without a second template, so **the front-end
developer and I have to agree on that file's shape before either of us finishes**. It is
the only place our two scopes actually touch code.

### What this platform is

An enquiry-driven content API for one tea brand. **No cart, no checkout, no payment**
(§2, §3.2). Prices are informational; conversion is product enquiries and dealer
applications — which is why §8.8 and the gapless reference numbers get disproportionate
attention.

Two independent auth systems share the API, separated by a JWT `aud` claim (§7). Beyond
that, authorisation has exactly one dimension: the admin's role (§7.3).

---

## 2. Working agreement

| Who | Does what |
|---|---|
| **You** | All `git` operations — init, add, commit, push, branches, GitHub. Anything needing a browser login or a credential (cPanel, Cloudinary, Google Cloud OAuth, SMTP, reCAPTCHA). Answering RTPP-17 and confirming the hosting prerequisites in RTPP-90. Assigning the front-end issues. |
| **Claude** | Writes and edits the backend in `backend/api/`. Runs local commands (composer, migrate, seed, test, lint). Moves my Jira issues through the workflow. Keeps this plan updated. |
| **Front-end dev** | RTPP-3, RTPP-4, and RTPP-76/77/78. Consumes the API contract in §9. |

I will not run `git` commands or push anything. At the end of each phase I will tell you
what changed so you can review and commit.

### Jira update protocol

Start of a phase: my issues go `To Do → In Progress`. End of a phase, once its Definition
of Done actually passes: → `Done`, and the box in §8 gets ticked. I will not mark an issue
Done on "code written" alone — the verification has to pass and I'll show the output.

Transitions (team-managed, global): To Do `11` · In Progress `21` · Review `31` · Done `41`.

---

## 3. Current state — Phase 1 in progress

Started 2026-09-07. The Node/Prisma implementation was deleted after salvaging its data.

### Done and verified

| | |
|---|---|
| **Toolchain** | PHP 8.3.33. MySQL **8.0.46** on port **3307**, own datadir, own socket, driven by `backend/api/bin/mysql8.sh`. |
| **Schema (RTPP-9)** | 7 migrations + bookkeeping table, **generated from §8 of the doc**, in computed FK order. Applies to a virgin database: 36 tables (37 after RTPP-15 added `rate_limits`), 43 FKs, 0 non-utf8mb4, 0 non-InnoDB, every FK indexed, Bangla round-trips, `CHECK (id = 1)` rejects a second `site_profile` row on INSERT *and* UPDATE. |
| **Runner (RTPP-9)** | `bin/migrate.php` — forward-only, sha256 per applied file, refuses to run when an applied migration has been edited. Verified: virgin → apply → re-apply no-op → tamper refused → restore passes. |
| **Seeders (RTPP-10)** | 12 seeders + `bin/seed.php`, one transaction, FK order. Virgin database → **690 rows across 24 tables**: 64 districts, 493 upazilas, 1 site profile, 8 categories, 3 products / 6 pack sizes, 5 banners, 10 SEO rows, 12 settings, 19 menu links. A second run writes **0 rows**, asserted in CI. Reference data is corrected on re-run; client-owned content is never overwritten — both proved by editing rows and re-seeding. |
| **Security baseline (RTPP-15)** | Rate limiting on a **database counter**, not process memory — 100 req/15 min per IP globally, 5/hour per IP per public form, each form with its own budget. Proved live across 105 separate PHP processes: exactly 100 through, the 101st refused, `Retry-After` and `X-RateLimit-*` headers set. Health checks and preflights exempt. Plus a parameter-pollution guard, HTTPS refusal in production, and the front-end CSP delivered as `deploy/frontend.htaccess`. |
| **Site profile (RTPP-14)** | `GET /public/layout` — one unauthenticated, header-free call returning site identity, logos, theme, contact, map, grouped menus, social links and newsletter visibility. `GET|PATCH /admin/site-profile`, Super-Admin-only, writing through an allowlist with per-field validation. Verified live: changing `primary_color` in the database changes the next response (§18.2), a non-Super-Admin PATCH is refused 403 by the API, and every optional field returns null rather than breaking. 28 tests. |
| **Authorisation (RTPP-13)** | `RolePolicy` — the §7.3 matrix as data, 16 capabilities × 3 roles — plus `RequireRole` middleware with four ordered levels (`NONE < READ < OWN < WRITE`) and deny-by-default. **214 tests** cover it: 48 assert the matrix against a hand-transcribed second copy, 144 drive every capability × level × role through the real middleware chain with a real signed token. `GET /auth/admin/me` now returns the caller's matrix row so the dashboard's hidden navigation cannot disagree with the API. |
| **Customer auth (RTPP-12)** | 6 endpoints under `/auth/customer/*`. Google ID tokens verified server-side against Google's JWKS — RS256 signature, `alg` allowlist, issuer, **audience**, expiry, `email_verified` — with a hand-written JWK→PEM converter proved byte-identical to OpenSSL's own output. Keys cached on disk from their `Cache-Control` TTL (186 ms → 0.1 ms), one forced refetch on a key rotation. 22 unit tests sign real RS256 tokens with a generated key pair, so forgery rejection is actually exercised. |
| **Admin auth (RTPP-11)** | 11 endpoints under `/auth/admin/*`. HS256 JWT (hand-written verifier: rejects `alg:none`, tampering, wrong secret, wrong audience; `hash_equals` throughout), argon2id credentials, refresh rotation with family-wide reuse detection, `HttpOnly` cookie path-scoped to `/api/v1/auth`, login throttle per email **and** per IP. Verified live end to end and by 23 database-backed tests. |
| **Salvaged data** | `backend/api/database/seed-data/bd-locations.json` (64 districts, 493 upazilas) and `.../site-content.json` (green brand only, reshaped to `site_profile`). Moved under `backend/api/` in RTPP-10 so the seeders ship with the deployable directory. |

**Why MySQL 8 sits beside your MySQL 9.6 rather than replacing it.** This machine runs
9.6.0 on 3306. The host will run MySQL 8 or MariaDB. Authoring a schema on 9.x risks
9-only syntax reaching a host that cannot run it — the same version-skew trap that cost a
day under the Node stack, when Homebrew Postgres 14 was listening on the port the project
expected 16 on. 8.0 therefore gets its own port and datadir, and 9.6 is untouched.

### Found in the document while building

**`reference_counters.last_value` would not parse.** `LAST_VALUE` is a window function in
MySQL 8. Renamed to `last_seq` in §8.2 — more accurate anyway, since it is a sequence
number. Fixed locally; **Confluence §8 still carries the old line** and is synced at the
end of Phase 1.

**MySQL's `NOW()` was six hours ahead of the application's clock.** The app writes
every `DATETIME(3)` as a UTC string built in PHP, but the MySQL session inherited the
machine's Asia/Dhaka timezone. Nothing was broken — the application never mixed the two —
but any future query comparing a stored timestamp against `NOW()` would have been silently
wrong: a rate-limit window that never expires, a token that never times out. Found while
writing a rate-limit test. The session is now pinned to `+00:00` on connect.

Also worth recording: **§8's presentation order is not apply-order.** `site_profile` is
documented first but must be created 32nd, because it references `media_assets`. The
migrations are ordered by a dependency graph computed from the DDL, not by section order.

### Remaining in Phase 1

RTPP-90 (hosting gate, **still unconfirmed**) · RTPP-16 (OpenAPI, health).

---

## 4. Environment

### 4.1 The hosting gate — RTPP-90

§16.2 lists seven prerequisites. **Two can invalidate the plan**, and neither is confirmed
against the real account yet:

| # | Prerequisite | If it fails |
|---|---|---|
| 1 | **PHP 8.2 or 8.3 in MultiPHP Manager** | **Fatal.** You picked 8.3 from the spec; that is not the same as the account offering it. Must be checked before Phase 6. |
| 2 | MySQL 8 or MariaDB 10.6+ | Recoverable — §8 uses no MySQL-8-only syntax. `CHECK` needs MySQL 8.0.16+ or MariaDB 10.2+; both below what we ask for. |
| 3 | Subdomain allowance for `admin.` and `api.` | Recoverable — merge onto one host with path routing. |
| 4 | **Document root settable per domain** | **Fatal to the security posture.** Without it `app/`, `config/` and `.env` sit web-reachable and the §13 `.htaccess` fallback must be security-reviewed. |
| 5 | SSH / Terminal | Recoverable — build `vendor/` locally, migrate via the `CRON_TOKEN` endpoint. |
| 6 | Cron | Recoverable but costly — every job in `app/Jobs/` depends on it. |
| 7 | AutoSSL on three hostnames | Recoverable — manual certificates. |

### 4.2 Local

| Item | State |
|---|---|
| PHP | 8.3.33 at `/opt/homebrew/opt/php@8.3/bin/php` — **not** on `PATH`; scripts call it by full path so your system PHP (none) stays unaffected |
| MySQL | 8.0.46, port 3307, datadir `/opt/homebrew/var/mysql8-rajdhani`, socket `/tmp/mysql8-rajdhani.sock` |
| Your MySQL 9.6 | Untouched, still on 3306 |
| Composer | **Not yet installed** — needed by RTPP-7 |
| Node | v23.8.0 — front-end build tooling only, no Node in production |

### 4.3 Credentials still needed

- **cPanel access** → RTPP-90, before Phase 6
- `GOOGLE_CLIENT_ID` + secret → RTPP-12. Building against a placeholder; sign-in cannot be verified end-to-end without it
- Cloudinary cloud name / key / secret → RTPP-21
- SMTP host, port, user, pass → RTPP-33
- reCAPTCHA v3 secret → RTPP-34

**Assumed until told otherwise:** the admin dashboard lives at `admin.rajdhanifood.com`,
which sets `CORS_ORIGINS` and the cookie domain.

---

## 5. Structure

Exactly the §13 tree. Only `public/` is web-exposed.

```
backend/api/
├── public/          index.php (front controller), .htaccess, uploads/
├── config/          app, database, cors, cloudinary, mail, auth
├── routes/          api.php · auth.php · public.php · admin.php
├── app/
│   ├── Controllers/ HTTP only: parse, delegate, respond
│   ├── Services/    business rules; the only caller of repositories
│   ├── Repositories/the only layer that issues SQL
│   ├── Models/      row objects
│   ├── Middleware/  Cors, AuthCustomer, AuthAdmin, RequireRole,
│   │                ValidateRequest, RateLimit, AuditLog
│   ├── Validators/  one per resource
│   ├── Helpers/     ApiResponse, ApiError, Pagination, SlugHelper,
│   │                ReferenceGenerator, UlidHelper, AuthHelper
│   ├── Mail/        templates + senders
│   └── Jobs/        cron-invoked, never daemons
├── database/
│   ├── migrations/  ✅ 7 files + bookkeeping, forward-only
│   └── seeders/     SiteProfile, District, Upazila, Admin, Category
├── storage/logs/    outside public/, daily rotation
├── bin/             migrate.php ✅ · mysql8.sh ✅
├── tests/           Unit/ · Feature/
└── composer.json
```

**Layering contract (§13).** Controllers handle HTTP only. Services hold business rules
and are the only layer that talks to repositories. Repositories are the only layer that
issues SQL. Nothing crosses sideways: a controller never touches a repository, a service
never touches `$_REQUEST`.

**The API contract is the interface to the other half of the project.** §9 is what the
front-end developer builds against. Changing a route shape or an envelope after they start
is a breaking change for someone who is not in this repository — so §9 gets updated in the
document *first*, and the OpenAPI spec (RTPP-16) is the artefact they consume.

---

## 6. Phases

### ◐ Phase 1 — Foundation · RTPP-1 · 43 pts · 2 weeks · **in progress**

| # | Work package | Jira | Pts | State |
|---|---|---|---|---|
| 1.0 | Confirm the seven §16.2 hosting prerequisites | RTPP-90 | 3 | ☐ blocked on you |
| 1.1 | Backend scaffold — Composer, PSR-4, PSR-12, PHPStan, PHPUnit, CI | RTPP-7 | 2 | ☑ **done** |
| 1.2 | Front controller, router, config, PSR-3 logging, envelope, helpers | RTPP-8 | 5 | ☑ **done** |
| 1.3 | MySQL schema, migration runner, versioned migrations | RTPP-9 | 8 | ☑ **done** |
| 1.4 | Seeders — site profile, districts/upazilas, super admin, demo content | RTPP-10 | 3 | ☑ **done** |
| 1.5 | Admin auth — argon2id, JWT pair, refresh cookie, rotation | RTPP-11 | 5 | ☑ **done** |
| 1.6 | Customer auth — Google OAuth | RTPP-12 | 5 | ☑ **done** |
| 1.7 | Authorization — the §7.3 role matrix, server-side | RTPP-13 | 3 | ☑ **done** |
| 1.8 | Site profile service and `GET /public/layout` | RTPP-14 | 3 | ☑ **done** |
| 1.9 | Security baseline — CORS, headers, rate limiting | RTPP-15 | 3 | ☑ **done** |
| 1.10 | OpenAPI specification and health endpoints | RTPP-16 | 3 | ☐ |
| — | Client decisions (§19) — parallel, not a build task | RTPP-17 | — | ☐ blocked on you |

**Definition of Done**

- Seven §16.2 prerequisites answered in writing on RTPP-90; items 1 and 4 viable, or the
  plan is re-cut before Phase 2
- ☑ `php bin/migrate.php up` takes a **virgin** database to the full §8 schema; a second
  run is a no-op
- ☑ A second `site_profile` row is rejected **by the database**
- Seeders run twice with no duplicates; row counts recorded
- `GET /health` 200; `GET /health/db` 503 with the database stopped, 200 when it returns
- `GET /public/layout` returns the site profile; changing `primary_color` changes the
  response
- Admin and customer tokens mutually invalid (`aud` claim)
- A request from an origin outside `CORS_ORIGINS` is rejected before any controller runs
- Every `ApiError` returns the §9.1 envelope; an unhandled error returns 500 with no stack
  trace when `APP_DEBUG` is off
- Requesting `/.env` or `/config/database.php` over HTTP returns 403/404, never contents
- The OpenAPI document lists every route implemented so far
- PHPUnit green; PHPStan clean

---

### ☐ Phase 2 — Core API · RTPP-2 · 83 pts · 2.5 weeks

Dependency order — media before products (products reference `media_assets`),
notifications before leads (every submission sends mail).

| Order | Work package | Jira | Pts |
|---|---|---|---|
| 1 | Media — signed direct-to-Cloudinary upload, asset registry | RTPP-21 | 5 |
| 2 | Media deletion with cross-table reference check | RTPP-22 | 3 |
| 3 | Categories | RTPP-18 | 3 |
| 4 | Products — pack sizes, highlights, images, SEO | RTPP-19 | 8 |
| 5 | Public catalogue — listing, filtering, detail, related | RTPP-20 | 5 |
| 6 | Banners — placements, scheduling, ordering | RTPP-23 | 3 |
| 7 | Content blocks — features, steps, stats, certs, testimonials, page blocks | RTPP-24 | 8 |
| 8 | Gallery | RTPP-25 | 3 |
| 9 | News | RTPP-26 | 3 |
| 10 | Email notification service (§14.4) | RTPP-33 | 5 |
| 11 | Form protection — reCAPTCHA v3 + honeypot | RTPP-34 | 3 |
| 12 | Reviews — moderation, rating aggregates | RTPP-27 | 5 |
| 13 | Wishlist — server-persisted, guest merge | RTPP-28 | 3 |
| 14 | Enquiries with gapless reference numbers | RTPP-29 | 5 |
| 15 | Dealer applications + district/upazila service | RTPP-30 | 5 |
| 16 | Contact messages and newsletter | RTPP-31 | 3 |
| 17 | Settings, menus, social links, downloads | RTPP-32 | 5 |
| 18 | Audit logging | RTPP-35 | 3 |
| 19 | Aggregate payloads, dashboard summary, cache purge | RTPP-36 | 5 |

**Definition of Done**

- Every module follows §13 layering; a static check confirms no controller imports a
  repository and no service reads superglobals
- A validator class on every body, param and query
- All SQL through PDO prepared statements; no interpolation anywhere
- **50 concurrent enquiry submissions produce 50 unique, gapless references** —
  load-tested, not reasoned about (§18.5). A rolled-back insert leaves no gap
- Killing SMTP still returns 200 on a submission, with the failure logged (§14.4)
- Every public form endpoint rate-limited and reCAPTCHA-protected
- Deleting a referenced media asset returns `409 CONFLICT` naming dependents (§12)
- Duplicate slug, duplicate SKU and duplicate newsletter email rejected **by the database**
- **The OpenAPI document covers the full public and admin surface** — this is the artefact
  the front-end developer builds against, so it ships with the phase, not after it

---

### ☐ Phase 5 — backend slice · RTPP-5 · 13 pts

| Work package | Jira | Pts |
|---|---|---|
| **PHP shell renderer** — server-injected meta, OG, JSON-LD, `<noscript>`, sitemap (§14.3) | RTPP-73 | 8 |
| API performance — indexes, query profiling, `EXPLAIN` review | RTPP-79 | 5 |

RTPP-73 needs the front-end's built `index.html` to exist first — sequence it after their
Phase 4. **Done when** fetching home, products, a product detail and a news article **with
JavaScript disabled** returns correct title, description, OG tags, canonical and JSON-LD
(§18.10), and the Facebook and LinkedIn debuggers render a correct card.

RTPP-79 **must be measured against the real shared-hosting account**, not locally —
noisy neighbours and connection caps do not reproduce on a developer machine.

---

### ☐ Phase 6 — backend & devops slice · RTPP-6 · 19 pts

| Work package | Jira | Pts |
|---|---|---|
| Staging environment with seeded demo data | RTPP-80 | 3 |
| CI/CD pipelines | RTPP-81 | 5 |
| Production hosting — cPanel domains, document roots, PHP version, AutoSSL | RTPP-82 | 5 |
| Database backups and rehearsed restore | RTPP-83 | 3 |
| Monitoring and uptime checks | RTPP-84 | 3 |

**Done when** all three hostnames serve over HTTPS with auto-renewing certificates;
a merge to `main` deploys with no manual steps and a rollback has been drilled;
**a database restore has been performed and timed** from a real backup (§18.11);
log rotation is confirmed working — an unrotated log fills the shared-hosting quota and
takes the site down; and cron is live for `TokenCleanup`, `MediaCleanup`, `LeadDigest`,
`SitemapBuild` and the nightly `mysqldump`.

---

## 7. Conventions

- **Envelope** (§9.1): `{ success, data, meta }` / `{ success, error: { code, message, details } }`
- **Error codes:** `VALIDATION_ERROR`, `UNAUTHENTICATED`, `TOKEN_EXPIRED`, `FORBIDDEN`,
  `NOT_FOUND`, `CONFLICT`, `RATE_LIMITED`, `UPLOAD_FAILED`, `INTERNAL_ERROR`
- **List params:** `page`, `limit`, `search`, `sort`, `order` plus resource filters
- **Primary keys:** ULID in `CHAR(26)`, generated in PHP. The one exception is
  `site_profile`, a `TINYINT` singleton pinned by `CHECK (id = 1)`
- **Naming:** `snake_case`, plural tables; API exposes the same names, with
  `before_json` / `after_json` surfaced as `before` / `after`
- **Timestamps:** `DATETIME(3)` UTC, application-managed; ISO 8601 UTC over the wire
- **Money:** `DECIMAL(10,2)`, never float
- **SQL:** PDO prepared statements only, always inside a repository
- **Migrations:** forward-only; the runner refuses to proceed if an applied file changed
- **Secrets:** environment variables only, never committed

---

## 8. Progress

- ◐ Phase 1 · ☐ Phase 2 · ☐ Phase 5 slice · ☐ Phase 6 slice

**9 / 37 issues complete** — RTPP-7 through RTPP-15. **37 of Phase 1's 43 points.**

Green on every gate: `composer check` passes (0 style issues, 0 PHPStan errors at
level 8, 31 tests / 63 assertions), migrations apply to a virgin database and
re-run as a no-op, and the API boots and answers correctly.

---

## 9. Risks

| Risk | Impact | Handling |
|---|---|---|
| **§16.2 items 1 or 4 fail** | Item 1 invalidates the stack; item 4 puts `app/`, `config/` and `.env` in a web-reachable tree | RTPP-90. Still unanswered — you chose PHP 8.3 from the spec, which is not the same as the account offering it. |
| **The API contract drifts after the front-end starts** | A route or envelope change breaks someone outside this repo, discovered late | §9 changes in the document first; the OpenAPI spec ships with Phase 2, not after. Version the API before changing a shipped shape. |
| **RTPP-73 depends on the front-end's `index.html`** | The one place our scopes touch code | Agree the file's shape with the front-end developer during their Phase 4, before either side finishes. |
| Gapless reference numbers under concurrency | Duplicate or skipped enquiry IDs are client-visible | `reference_counters` row locked with `SELECT … FOR UPDATE` inside the insert transaction (§8.2). `AUTO_INCREMENT` would **not** be gapless. Load-tested in RTPP-29. |
| RTPP-17 unanswered | Item 6 blocks RTPP-32; items 4 and 7 block RTPP-33 verification | Build behind a flag, verify when answers land. |
| Third-party credentials late | RTPP-12, 21, 33, 34 cannot be verified end-to-end | Placeholders and local fakes; keep a "needs real credentials" checklist. |
| No queue worker on shared hosting | A dead SMTP server could stall submissions | Commit first, send inline in try/catch with a short timeout, log failures. Escalation is a `mail_queue` table flushed by cron (§14.4). |
| Shared-hosting disk quota | An unrotated log fills it and takes the site down | Daily rotation plus a cron prune, verified in RTPP-84 — not assumed. |
| Host-imposed PHP limits | `max_execution_time`, `upload_max_filesize` not ours to set | Direct-to-Cloudinary upload keeps files out of PHP (RTPP-21); migrations run one file per invocation. |
| MySQL 9.6 also on this machine | Authoring against the wrong major version | 8.0.46 on its own port and datadir; 9.6 untouched. |

---

## 10. Agreed deviations

Recorded here and in §19 of the requirements document, so the document and the build stay
reconcilable at acceptance.

| # | Section | Change | Date |
|---|---|---|---|
| 1 | §5.3 | ORM pinned to Prisma 6. **Superseded by 2.** | 2026-09-06 |
| 2 | §4, §5, §8, §13 | Backend Node/Express/Prisma/PostgreSQL → **PHP 8.2/8.3 + MySQL 8**; customer site Next.js → React + Vite. Driven by shared cPanel hosting. Cost: SSR lost, replaced by the §14.3 shell renderer. | 2026-09-06 |
| 3 | §5.3, §8 | **MySQL 8**; MariaDB 10.6+ acceptable. No MySQL-8-only syntax. Confirm in RTPP-90. | 2026-09-06 |
| 4 | §16 | VPS → **shared cPanel hosting**. Consequences in §16.6. | 2026-09-06 |
| 5 | §2, §4, §6–§9, §11–§13, §16–§18 | **Second brand removed; single-site platform.** 37 → 36 tables, 15 → 13 weeks. Four constraints became stronger. §6.2 records the cost of reversing it. | 2026-09-07 |
| 6 | §8.2 | `reference_counters.last_value` → **`last_seq`**. `LAST_VALUE` is a MySQL 8 window function and the DDL would not parse. Found by applying the schema to a real database. | 2026-09-07 |
| 7 | — | **Scope split: this plan covers the backend only.** The front-end (RTPP-3, RTPP-4, RTPP-76/77/78) goes to a separate developer, assigned through Jira. The requirements document stays full-scope because they need it. | 2026-09-08 |

### Open technical questions

1. **Gapless reference numbers.** §13 asks for a *gapless* sequence. A native sequence or
   `AUTO_INCREMENT` cannot be — a rolled-back insert burns its value. Implementation uses
   a locked counter row (§8.2). If gaps are acceptable, a simpler mechanism will do.
2. **Is organic search a primary acquisition channel?** §14.3 documents a real loss of SEO
   strength versus the withdrawn server-rendered design. If it matters more than assumed,
   revisit rendering **before the front-end's Phase 4 begins**.
3. **Is the second brand out of scope or deferred?** §6.2 sets out the retrofit cost.
   Tracked as RTPP-17 item 9.

---

*Backend plan v3.1, 2026-09-08. Tracks `Rajdhani_Project_Doc.md` v3.0.*
