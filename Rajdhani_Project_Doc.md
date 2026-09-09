# Rajdhani Tea Platform — Project Requirements Document

**Version 3.0** — single-site: PHP 8 / MySQL 8 / React + Vite, deployed on shared
cPanel hosting. Supersedes v2.0 (two-brand) and v1.0 (Node / Express / Prisma /
PostgreSQL / Next.js). See the agreed deviations table in §19 for what changed and why.

**Client:** Rajdhani Food Products
**Prepared by:** Jamuna Tech
**Version:** 3.0
**Date:** 07 September 2026
**Status:** For review and sign-off

---

## Table of Contents

1. [Purpose of This Document](#1-purpose-of-this-document)
2. [Project Overview](#2-project-overview)
3. [Scope](#3-scope)
4. [System Architecture](#4-system-architecture)
5. [Technology Stack](#5-technology-stack)
6. [Site Model & Configuration](#6-site-model--configuration)
7. [Authentication & Authorization](#7-authentication--authorization)
8. [Database Schema](#8-database-schema)
9. [API Route Specification](#9-api-route-specification)
10. [Customer Website — Pages & Requirements](#10-customer-website--pages--requirements)
11. [Admin Dashboard — Modules & Screens](#11-admin-dashboard--modules--screens)
12. [Media Handling (Cloudinary)](#12-media-handling-cloudinary)
13. [Backend Modular Architecture](#13-backend-modular-architecture)
14. [Non-Functional Requirements](#14-non-functional-requirements)
15. [Environment Configuration](#15-environment-configuration)
16. [Deployment Plan](#16-deployment-plan)
17. [Delivery Phases](#17-delivery-phases)
18. [Acceptance Criteria](#18-acceptance-criteria)
19. [Open Items & Assumptions](#19-open-items--assumptions)

---

## 1. Purpose of This Document

This document defines the complete technical and functional requirements for the Rajdhani tea platform. It is the reference point for development, QA, and client sign-off. It covers the database schema, API routes, authentication design, content model, admin capabilities, and deployment topology.

Anything not described here is out of scope for the agreed budget and timeline and will be handled as a change request.

---

## 2. Project Overview

Rajdhani requires a content-managed web platform for a single tea brand:

| Site | Theme | Domain | Product Line |
|---|---|---|---|
| Rajdhani Food Products | Green / Gold | rajdhanifood.com | Premium Tea, Gold Blend, Classic Black, Green Tea, Tea Bags, Loose Tea, Masala, Cardamom |

> **Changed in v3.0.** v2.0 of this document specified a second brand, Rajdhani Milk
> Added Tea, on `rajdhanitea.com` with a red/yellow theme. Those designs were supplied
> in error and the brand was never in scope. Everything that supported it has been
> removed — see §6.1 and deviation 5 in §19. Milk-tea *products* remain perfectly
> ordinary catalogue items under this one site if the client wants to sell them.

The platform is **enquiry-driven, not e-commerce**. There is no cart, no checkout, and no online payment. Products display pricing for information, and business is captured through product enquiries and dealer/distributor applications.

### Primary Business Goals

- Present the brand professionally with a strong, SEO-friendly public presence.
- Capture and manage B2B leads (product enquiries, dealer/distributor applications).
- Allow non-technical staff to manage all content — banners, products, gallery, news, statistics, certifications — without developer involvement.
- Build customer trust through moderated reviews and visible quality/certification information.

### User Types

| User | Access | Capability |
|---|---|---|
| Public visitor | Customer website, unauthenticated | Browse all content, submit enquiries, dealer applications, contact messages, newsletter signup |
| Registered customer | Customer website, Google login | All of the above, plus persistent wishlist and review submission |
| Sales | Admin dashboard | View and manage enquiries, dealer applications, contact messages, newsletter list |
| Editor | Admin dashboard | Manage content — products, banners, gallery, news, pages |
| Super Admin | Admin dashboard | Everything, including admin user management, site settings, and audit log |

---

## 3. Scope

### 3.1 In Scope

- Customer website (React + Vite SPA) — responsive, single domain.
- Admin dashboard (React + Vite SPA) — full content management.
- Backend REST API (PHP 8.2/8.3 + MySQL 8) — deployed independently.
- Customer authentication via Google OAuth.
- Admin authentication via manually issued credentials.
- Dynamic content: banners, products, categories, gallery, news, testimonials, certifications, statistics, process steps, feature strips, page copy, downloadable files, footer and contact details.
- Lead capture: product enquiries, dealer/distributor applications with district/upazila selection and application ID generation, contact form, newsletter.
- Moderated product reviews with computed rating aggregates.
- Server-persisted wishlist for logged-in customers.
- Cloudinary-based media management.
- Email notifications for form submissions.
- Audit logging of admin actions.

### 3.2 Out of Scope (Phase 1)

- Shopping cart, checkout, payment gateway, order management.
- Bangla or any multi-language support. (Schema uses plain columns; adding i18n later is a change request.)
- Mobile applications.
- Inventory or stock management, ERP/accounting integration.
- Customer-facing dashboards beyond wishlist and review history.
- Live chat.
- SMS notifications.
- Migration of data from any existing system.
- **A second brand or any form of multi-tenancy.** Removed in v3.0; §6.2 states what
  reintroducing it would cost and why the decision point is before Phase 1, not after
  launch.

---

## 4. System Architecture

Three independently deployed applications. Both front-ends are **static builds** —
compiled HTML, CSS and JavaScript with no server runtime of their own. Only the
API executes server-side code.

```
                          ┌──────────────────────────────┐
   rajdhanifood.com  ───► │  Customer Website            │
                          │  React + Vite (static SPA)   │ ──┐
                          └──────────────────────────────┘   │
                                                             │   HTTPS / REST
                          ┌──────────────────────────────┐   │   JSON
   admin.rajdhani...  ──► │  Admin Dashboard             │   │
                          │  React + Vite (static SPA)   │ ──┤
                          └──────────────────────────────┘   │
                                                             ▼
                          ┌──────────────────────────────────────────────┐
                          │  Backend API                                 │
                          │  PHP 8.2/8.3, REST, modular                  │
                          │                                              │
                          │  ┌────────────────┐   ┌───────────────────┐  │
                          │  │  MySQL 8       │   │  Cloudinary (CDN) │  │
                          │  └────────────────┘   └───────────────────┘  │
                          │  ┌────────────────┐   ┌───────────────────┐  │
                          │  │  Redis (opt.)  │   │  SMTP provider    │  │
                          │  └────────────────┘   └───────────────────┘  │
                          └──────────────────────────────────────────────┘
```

### 4.1 Cross-Origin Policy

Because all three tiers live on separate origins, the API enforces a strict CORS
allowlist driven by environment variables:

- `https://rajdhanifood.com`, `https://www.rajdhanifood.com`
- `https://admin.rajdhanifood.com`

`Access-Control-Allow-Credentials: true` is required so refresh-token cookies flow
correctly. Refresh cookies are issued with `SameSite=None; Secure; HttpOnly` and a
`Domain` scoped per client. Any origin not on the allowlist is rejected in
middleware before reaching a controller.

### 4.2 Request Flow — Customer Website

1. Browser requests `rajdhanifood.com/products`. The web server returns
   `index.html` for any path that is not a real file (SPA fallback rewrite).
2. The bundle boots and fetches `GET /public/layout`, which returns the
   `site_profile` row: name, logos, theme colours, contact block and menus.
3. Theme colours are applied as CSS custom properties at the document root, so a
   colour change in the admin panel restyles the running site with no deploy.
4. Every API request goes to the same base URL. **There is no `X-Brand` header and
   no brand resolution step** — the API serves one site.
5. Content is fetched client-side and cached by TanStack Query. Admin publishes
   are visible on the next fetch — there is no build step or cache to invalidate.

> **Rendering change from v1.** This document previously specified Next.js with
> SSR and ISR. A React + Vite SPA renders on the client, so public pages ship an
> empty HTML shell. See §14.3 for what that costs in SEO and how it is mitigated.

### 4.3 Request Flow — Admin Dashboard

1. Admin logs in with email and password at the admin origin.
2. API returns a short-lived access token (held in memory only) and sets a refresh
   cookie.
3. Every admin request carries the bearer access token.
4. Middleware verifies the token and checks role permission for the route against
   the §7.3 matrix. There is no brand switcher and no per-brand access check —
   role is the only dimension of authorisation.

## 5. Technology Stack

### 5.1 Customer Website

| Concern | Choice |
|---|---|
| Framework | React 18 + Vite |
| Language | JavaScript (TypeScript optional per module) |
| Routing | React Router v6 |
| Styling | Tailwind CSS with CSS custom properties driven by `site_profile` |
| Server state | TanStack Query |
| Client state | Zustand (auth, wishlist, site profile) |
| Forms | React Hook Form + Zod |
| SEO | `react-helmet-async` for meta and JSON-LD; prerendered public pages (§14.3) |
| Images | Cloudinary delivery URLs with `f_auto,q_auto` and responsive `w_` variants |
| Build output | Static bundle served from the web root with an SPA fallback rewrite |

### 5.2 Admin Dashboard

| Concern | Choice |
|---|---|
| Framework | React 18 + Vite |
| Language | JavaScript (TypeScript optional per module) |
| Routing | React Router v6 |
| Server state | TanStack Query |
| UI | Tailwind CSS |
| Forms | React Hook Form + Zod |
| Tables | TanStack Table (sorting, pagination, filtering) |
| Rich text | TipTap |
| Charts | Recharts |
| Uploads | Direct-to-Cloudinary with signed upload params from the API |

### 5.3 Backend

| Concern | Choice |
|---|---|
| Language | PHP 8.2 / 8.3 |
| API style | REST, JSON only |
| Database | MySQL 8 (or MariaDB 10.6+ — see §19 deviation 3) |
| DB access | PDO with prepared statements; Eloquent where a framework provides it |
| Migrations | Versioned SQL migrations under `database/migrations/` |
| Validation | Per-resource validator classes; every body, param and query validated |
| Auth | JWT access + refresh tokens, `argon2id` password hashing, Google OAuth |
| Media | Cloudinary PHP SDK |
| Email | SMTP via PHPMailer or framework mailer |
| Security | CORS allowlist, security headers, rate limiting, reCAPTCHA v3 |
| Logging | PSR-3 logger writing to `storage/logs/`, daily rotation |
| Docs | OpenAPI specification maintained alongside the routes |
| Testing | PHPUnit or Pest — unit and feature suites |
| Runtime | PHP-FPM behind the host's web server |
| Cache | Redis — optional, only if the host provides it |

### 5.4 Hosting

Deployment targets **shared cPanel hosting** (§16). This constrains several
choices that a VPS would leave open:

| Constraint | Consequence |
|---|---|
| No root access | Web server, PHP-FPM pool and TLS are managed by the host, not by us |
| No Docker | No containerised local-parity environment on the server |
| No long-running processes | No queue workers or daemons; background work runs via cPanel cron |
| Redis usually unavailable | The optional cache layer is likely to stay unused |
| Composer may be unavailable | If there is no SSH, `vendor/` is built locally and uploaded |

These are why the backend is plain PHP over PDO rather than anything requiring a
persistent process.


## 6. Site Model & Configuration

**This is a single-site platform.** One brand, one domain, one product catalogue,
one admin panel. There is no tenancy dimension anywhere in the data model, the
API, or the front end.

The rules that follow are what replaced the multi-brand model, and they are
deliberately short — that is the point of the change.

1. **Site identity is one database row.** `site_profile` (§8.2) holds the name,
   tagline, logos, favicon, theme colours, address, phone numbers, email
   addresses, business hours, map coordinates, footer copy and default SEO meta.
   It is a singleton enforced by a `CHECK (id = 1)` constraint, so a second site
   cannot be created by accident.
2. **Uniqueness is natural.** A product slug is unique because it is unique. The
   same holds for category slugs, news slugs, gallery slugs, setting keys, page
   keys, SKUs and newsletter emails — plain `UNIQUE` keys, no composites.
3. **Accounts are simply accounts.** A customer signing in with Google exists
   once. An admin's permissions come from `role` and the §7.3 matrix alone.
4. **Theming is still data, not code.** The React app reads the three colour
   columns from `site_profile` and injects them as CSS custom properties on the
   document root at boot. No component may reference a colour literal — that rule
   survives the change, because it is what keeps §18.2 true.
5. **No request carries a site identifier.** No `X-Brand` header, no
   `ResolveBrand` middleware, no `400 BRAND_REQUIRED`, no `403 BRAND_FORBIDDEN`.
   A repository method takes the arguments its query needs and nothing more.

### 6.1 What this replaced

v2.0 of this document specified two brands — Rajdhani Food Products (green/gold)
and Rajdhani Milk Added Tea (red/yellow) — sharing one codebase and one database,
separated by a `brand_id` column on 23 tables and an `X-Brand` header on every
request. **The red/gold designs were supplied in error and the second brand was
never in scope.** Recorded as deviation 5 in §19.

Removing it took out: the `brands` table, `admin_brand_access`, `brand_id` and its
foreign key on 23 tables, every `(brand_id, …)` composite unique key and index,
the `ResolveBrand` middleware, the repository brand-scope guard, the host-to-brand
resolver on the customer site, the admin brand switcher, the second domain, and
the cross-brand isolation test suite.

### 6.2 The cost of reintroducing it

Worth stating plainly, because "add the second brand later" is a request that gets
made:

- Adding `brand_id` to 23 populated tables is a migration, not a schema edit — every
  existing row needs a value, and every unique key has to be dropped and recreated
  as a composite. On shared hosting, against a live database, with no maintenance
  window, that is a genuinely risky afternoon.
- Every repository method signature changes, which means every service and every
  test changes with it.
- `site_profile` has to become a table with rows again, and everything that reads
  `WHERE id = 1` has to learn where its brand comes from.

None of that is unusual work, but it is roughly the two weeks this
change removes from the schedule (§17) — spent again, later, with production data
in the way. The right time to reverse this decision is **before** Phase 1 starts,
not after launch.

---
## 7. Authentication & Authorization

Two entirely separate authentication systems share one backend. They use different tables, different token secrets, different middleware, and different route namespaces. An admin token is never valid on a customer route and vice versa — enforced by an `aud` (audience) claim.

### 7.1 Customer Authentication — Google OAuth

**Flow:**

1. Customer clicks "Continue with Google" on the customer site.
2. Google Identity Services returns an ID token to the browser.
3. Browser posts the ID token to `POST /api/v1/auth/customer/google`.
4. Backend verifies the token against `GOOGLE_CLIENT_ID` using Google's published JWKS, checking issuer, audience, expiry, and `email_verified`.
5. Backend finds the customer by `google_id`, or by email if the account predates Google linking, or creates a new record.
6. Backend issues an access token and a refresh token.

**Token design:**

| Token | Lifetime | Storage | Claims |
|---|---|---|---|
| Access | 15 minutes | Memory / Zustand (never localStorage) | `sub`, `aud: "customer"`, `email`, `iat`, `exp` |
| Refresh | 30 days | HttpOnly, Secure, SameSite=None cookie | `sub`, `aud: "customer"`, `jti`, `iat`, `exp` |

- Refresh tokens are persisted hashed in `refresh_tokens` with a `jti`, device/user-agent string, and IP.
- Refresh rotation: each call to `/auth/customer/refresh` invalidates the presented token and issues a new one. Reuse of a revoked `jti` revokes the entire token family for that customer and forces re-login (breach detection).
- Logout deletes the stored refresh record and clears the cookie.

**What customer auth unlocks:** wishlist add/remove/list, review submission and review history, pre-filled enquiry forms, and a lightweight "My Account" page. Everything else on the customer site is public.

### 7.2 Admin Authentication — Manual Credentials

There is **no Google login and no self-registration for admins**. Accounts are created by a Super Admin from within the dashboard.

**Flow:**

1. Super Admin creates an admin with name, email, and role.
2. The system generates a one-time invite token (valid 48 hours) and emails a set-password link.
3. The invitee sets a password meeting policy: minimum 10 characters, at least one uppercase, one lowercase, one digit, one symbol. Passwords are hashed with **argon2id**.
4. Login at `POST /api/v1/auth/admin/login` returns an access token plus a refresh cookie.

**Token design:**

| Token | Lifetime | Storage | Claims |
|---|---|---|---|
| Access | 20 minutes | Memory only | `sub`, `aud: "admin"`, `role`, `iat`, `exp` |
| Refresh | 7 days | HttpOnly, Secure, SameSite=None cookie | `sub`, `aud: "admin"`, `jti` |

**Security controls:**

- Rate limit: 5 failed login attempts per email per 15 minutes, then a 30-minute lockout. Attempts are recorded in `login_attempts`.
- Forgot-password via emailed single-use token, valid 1 hour, invalidated on use.
- Changing a password revokes all that admin's refresh tokens.
- Deactivating an admin (`is_active = 0`) immediately revokes all their refresh tokens; the access token expires within 20 minutes.
- Optional TOTP two-factor for Super Admin accounts (Phase 2).

### 7.3 Role Permission Matrix

| Capability | Super Admin | Editor | Sales |
|---|:---:|:---:|:---:|
| Dashboard overview | ✔ | ✔ | ✔ (leads only) |
| Products & categories | ✔ | ✔ | read |
| Banners & page content | ✔ | ✔ | — |
| Gallery | ✔ | ✔ | — |
| News | ✔ | ✔ | — |
| Testimonials, certifications, stats, process steps | ✔ | ✔ | — |
| Review moderation | ✔ | ✔ | — |
| Downloads (brochure, catalogue) | ✔ | ✔ | read |
| Product enquiries | ✔ | read | ✔ |
| Dealer applications | ✔ | read | ✔ |
| Contact messages | ✔ | read | ✔ |
| Newsletter subscribers & export | ✔ | — | ✔ |
| Site settings & theme | ✔ | — | — |
| Admin user management | ✔ | — | — |
| Audit log | ✔ | — | — |
| Media library delete | ✔ | own uploads | — |

Permissions are enforced server-side by a `RequireRole` middleware on every admin route. The dashboard hides unavailable navigation, but the API is the source of truth.

### 7.4 Authorisation Scope

Role is the **only** dimension of admin authorisation. The §7.3 matrix is enforced
server-side by a `RequireRole` middleware on every admin route, and that is the whole
of it — there is no second check, no `RequireBrandAccess`, and no `403 BRAND_FORBIDDEN`.

Removed in v3.0 along with the second brand (§6.1). Under v2.0 an admin's access was
the intersection of their role and their `admin_brand_access` rows; with one site the
intersection is just the role.

---

## 8. Database Schema

MySQL 8 (InnoDB). This section is the authoritative shape of the data; the versioned migrations under `database/migrations/` implement it.

### 8.0 Conventions

| Convention | Choice | Why |
| --- | --- | --- |
| Engine | `InnoDB` | Foreign keys and transactions are both required (§13 reference numbers) |
| Charset | `utf8mb4` / `utf8mb4_unicode_ci` | District and upazila names carry Bangla (`name_bn`); `utf8` in MySQL is not real UTF-8 |
| Primary keys | `CHAR(26)` holding a **ULID** | Lexicographically sortable, so inserts append to the clustered index instead of fragmenting it the way random UUIDv4 does. Generated in PHP, not by the database. |
| Naming | `snake_case` tables and columns, plural table names | Standard MySQL/PHP convention |
| Timestamps | `created_at`, `updated_at` as `DATETIME(3)` | Millisecond precision; application-managed, in UTC |
| Soft delete | `deleted_at DATETIME(3) NULL` on admin-removable content | Accidental deletions are recoverable |
| Booleans | `TINYINT(1)` | MySQL has no native boolean |
| Enums | Native MySQL `ENUM` | Values are closed sets fixed by this document |
| Money | `DECIMAL(10,2)` | Never floating point |

**Single site.** This is a one-site schema. There is no `brand_id` column anywhere, no
composite `(brand_id, slug)` uniqueness, and no cross-tenant scoping to enforce: a slug is
unique because it is unique, and a query returns what it selects. See §6 for what this
replaced and what it would cost to reintroduce.

### 8.1 Enumerated Values

Declared inline on their columns; listed here as the canonical set.

| Enum | Values |
| --- | --- |
| `admin_role` | `SUPER_ADMIN`, `EDITOR`, `SALES` |
| `content_status` | `DRAFT`, `PUBLISHED`, `ARCHIVED` |
| `review_status` | `PENDING`, `APPROVED`, `REJECTED` |
| `enquiry_status` | `NEW`, `IN_PROGRESS`, `CONTACTED`, `CLOSED`, `SPAM` |
| `application_status` | `SUBMITTED`, `UNDER_REVIEW`, `APPROVED`, `REJECTED`, `ON_HOLD` |
| `message_status` | `UNREAD`, `READ`, `REPLIED`, `ARCHIVED` |
| `media_type` | `IMAGE`, `VIDEO`, `DOCUMENT` |
| `banner_placement` | `HOME_HERO`, `ABOUT_HERO`, `PRODUCTS_HERO`, `PRODUCT_DETAIL_HERO`, `QUALITY_HERO`, `DEALER_HERO`, `GALLERY_HERO`, `NEWS_HERO`, `CONTACT_HERO`, `HOME_PROMO`, `HOME_VIDEO_CARD`, `MID_PAGE_CTA`, `DEALER_CTA`, `SIDEBAR_AD` |
| `section_key` | `HOME_USP`, `HOME_WHY_US`, `ABOUT_VALUES`, `ABOUT_STRENGTH`, `QUALITY_COMMITMENT`, `DEALER_BENEFITS`, `CONTACT_ASSURANCE`, `PRODUCT_HIGHLIGHTS` |
| `process_group` | `FROM_GARDEN_TO_CUP`, `HOW_WE_MAKE_TEA`, `QUALITY_PROCESS`, `MANUFACTURING_PROCESS`, `BECOME_DEALER` |
| `stat_group` | `HOME`, `ABOUT`, `GALLERY`, `TEA_GARDEN`, `DEALER_NETWORK` |
| `reference_type` | `ENQ`, `DA` |

### 8.2 Site Profile & Settings

```sql
CREATE TABLE site_profile (
  id               TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- singleton; see note below
  name             VARCHAR(255)  NOT NULL,                -- 'Rajdhani Food Products'
  tagline          VARCHAR(255)  NULL,

  logo_light_id    CHAR(26)      NULL,
  logo_dark_id     CHAR(26)      NULL,
  favicon_id       CHAR(26)      NULL,
  og_image_id      CHAR(26)      NULL,

  primary_color    VARCHAR(9)    NOT NULL DEFAULT '#1B5E20',
  secondary_color  VARCHAR(9)    NOT NULL DEFAULT '#C9A227',
  accent_color     VARCHAR(9)    NOT NULL DEFAULT '#FFFFFF',

  address_line     VARCHAR(255)  NULL,
  city             VARCHAR(128)  NULL,
  country          VARCHAR(128)  NULL DEFAULT 'Bangladesh',
  phone_primary    VARCHAR(32)   NULL,
  phone_secondary  VARCHAR(32)   NULL,
  email_primary    VARCHAR(255)  NULL,
  email_secondary  VARCHAR(255)  NULL,
  website_url      VARCHAR(255)  NULL,
  business_hours   VARCHAR(255)  NULL,
  map_latitude     DOUBLE        NULL,
  map_longitude    DOUBLE        NULL,
  map_embed_url    TEXT          NULL,
  footer_about     TEXT          NULL,
  copyright_text   VARCHAR(255)  NULL,

  meta_title       VARCHAR(255)  NULL,
  meta_description TEXT          NULL,

  created_at       DATETIME(3)   NOT NULL,
  updated_at       DATETIME(3)   NOT NULL,

  PRIMARY KEY (id),
  KEY ix_site_profile_logo_light (logo_light_id),
  KEY ix_site_profile_logo_dark  (logo_dark_id),
  KEY ix_site_profile_favicon    (favicon_id),
  KEY ix_site_profile_og_image   (og_image_id),
  CONSTRAINT ck_site_profile_singleton CHECK (id = 1),
  CONSTRAINT fk_site_profile_logo_light FOREIGN KEY (logo_light_id) REFERENCES media_assets (id),
  CONSTRAINT fk_site_profile_logo_dark  FOREIGN KEY (logo_dark_id)  REFERENCES media_assets (id),
  CONSTRAINT fk_site_profile_favicon    FOREIGN KEY (favicon_id)    REFERENCES media_assets (id),
  CONSTRAINT fk_site_profile_og_image   FOREIGN KEY (og_image_id)   REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Why this table is not a ULID like everything else.** `site_profile` holds exactly one
row — the site's identity, theme, contact block and footer copy, all of which the admin
panel must be able to edit without a deployment (§18.2). A `TINYINT` primary key fixed at
`1` by a `CHECK` constraint makes a second row *impossible at the database level*, so no
application code has to defend against one and every read is `WHERE id = 1`. This is the
one deliberate exception to §8.0's ULID rule, and it is the direct replacement for the
`brands` table.

The alternative — folding these fields into the `settings` key-value table — was rejected:
colours, coordinates and media references are typed, and flattening them to `VARCHAR`
values loses the foreign keys to `media_assets` that keep logo deletion safe (§12).

```sql
CREATE TABLE settings (
  id         CHAR(26)     NOT NULL,
  `key`      VARCHAR(128) NOT NULL,          -- 'enquiry_notify_emails', 'gtm_id'
  value      TEXT         NOT NULL,
  `group`    VARCHAR(64)  NULL,              -- 'notifications' | 'analytics' | 'general'
  updated_at DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_links (
  id         CHAR(26)     NOT NULL,
  platform   VARCHAR(64)  NOT NULL,          -- facebook | instagram | linkedin | youtube | whatsapp
  url        VARCHAR(255) NOT NULL,
  icon_name  VARCHAR(64)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_social_links_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_links (
  id              CHAR(26)     NOT NULL,
  location        VARCHAR(64)  NOT NULL,     -- 'header' | 'footer_quick' | 'footer_products' | 'legal'
  label           VARCHAR(128) NOT NULL,
  url             VARCHAR(255) NOT NULL,
  parent_id       CHAR(26)     NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  open_in_new_tab TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_menu_links_location (location, is_active, sort_order),
  KEY ix_menu_links_parent (parent_id),
  CONSTRAINT fk_menu_links_parent FOREIGN KEY (parent_id) REFERENCES menu_links (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seo_meta (
  id               CHAR(26)     NOT NULL,
  page_key         VARCHAR(64)  NOT NULL,    -- 'home' | 'about' | 'products' | 'quality' ...
  meta_title       VARCHAR(255) NULL,
  meta_description TEXT         NULL,
  meta_keywords    VARCHAR(255) NULL,
  og_image_id      CHAR(26)     NULL,
  no_index         TINYINT(1)   NOT NULL DEFAULT 0,
  canonical_url    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seo_meta_page (page_key),
  KEY ix_seo_meta_og_image (og_image_id),
  CONSTRAINT fk_seo_meta_og_image FOREIGN KEY (og_image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Reference number counters.** §13 requires enquiry and application reference numbers that are **gapless** and collision-free under concurrency. A MySQL `AUTO_INCREMENT` is not gapless — a rolled-back insert permanently burns its value. A counter row incremented inside the same transaction as the insert is:

```sql
CREATE TABLE reference_counters (
  id         CHAR(26)          NOT NULL,
  type       ENUM('ENQ','DA')  NOT NULL,
  year       SMALLINT UNSIGNED NOT NULL,
  last_seq   INT UNSIGNED      NOT NULL DEFAULT 0,   -- not `last_value`: reserved (window function)
  updated_at DATETIME(3)       NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reference_counters (type, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The generator runs `SELECT … FOR UPDATE`, increments, and inserts the submission in one transaction. The row lock serialises concurrent writers; a rollback undoes the increment, so the series has no gaps. One counter row per type per year — two rows a year in total.

### 8.3 Accounts & Auth

```sql
CREATE TABLE admin_users (
  id                CHAR(26)     NOT NULL,
  name              VARCHAR(255) NOT NULL,
  email             VARCHAR(255) NOT NULL,
  password_hash     VARCHAR(255) NULL,        -- NULL until the invite is accepted
  role              ENUM('SUPER_ADMIN','EDITOR','SALES') NOT NULL DEFAULT 'EDITOR',
  avatar_id         CHAR(26)     NULL,
  phone             VARCHAR(32)  NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at     DATETIME(3)  NULL,
  invite_token      CHAR(64)     NULL,
  invite_expires_at DATETIME(3)  NULL,
  reset_token       CHAR(64)     NULL,
  reset_expires_at  DATETIME(3)  NULL,
  created_by_id     CHAR(26)     NULL,
  created_at        DATETIME(3)  NOT NULL,
  updated_at        DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email        (email),
  UNIQUE KEY uq_admin_users_invite_token (invite_token),
  UNIQUE KEY uq_admin_users_reset_token  (reset_token),
  KEY ix_admin_users_avatar  (avatar_id),
  KEY ix_admin_users_creator (created_by_id),
  CONSTRAINT fk_admin_users_avatar  FOREIGN KEY (avatar_id)     REFERENCES media_assets (id),
  CONSTRAINT fk_admin_users_creator FOREIGN KEY (created_by_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id             CHAR(26)     NOT NULL,
  google_id      VARCHAR(64)  NULL,
  email          VARCHAR(255) NOT NULL,
  name           VARCHAR(255) NOT NULL,
  avatar_url     VARCHAR(512) NULL,
  phone          VARCHAR(32)  NULL,
  city           VARCHAR(128) NULL,
  company_name   VARCHAR(255) NULL,
  email_verified TINYINT(1)   NOT NULL DEFAULT 1,   -- Google-verified
  is_blocked     TINYINT(1)   NOT NULL DEFAULT 0,
  last_login_at  DATETIME(3)  NULL,
  created_at     DATETIME(3)  NOT NULL,
  updated_at     DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_google_id (google_id),
  UNIQUE KEY uq_customers_email     (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refresh_tokens (
  id          CHAR(26)     NOT NULL,
  jti         CHAR(36)     NOT NULL,
  token_hash  VARCHAR(255) NOT NULL,
  audience    ENUM('customer','admin') NOT NULL,
  admin_id    CHAR(26)     NULL,
  customer_id CHAR(26)     NULL,
  family_id   CHAR(36)     NOT NULL,          -- rotation family, for reuse detection
  user_agent  VARCHAR(512) NULL,
  ip_address  VARCHAR(45)  NULL,              -- 45 chars fits IPv6
  expires_at  DATETIME(3)  NOT NULL,
  revoked_at  DATETIME(3)  NULL,
  created_at  DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refresh_tokens_jti (jti),
  KEY ix_refresh_tokens_admin    (admin_id),
  KEY ix_refresh_tokens_customer (customer_id),
  KEY ix_refresh_tokens_family   (family_id),
  KEY ix_refresh_tokens_expires  (expires_at),
  CONSTRAINT fk_refresh_tokens_admin    FOREIGN KEY (admin_id)    REFERENCES admin_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_refresh_tokens_customer FOREIGN KEY (customer_id) REFERENCES customers (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id         CHAR(26)     NOT NULL,
  email      VARCHAR(255) NOT NULL,
  audience   ENUM('customer','admin') NOT NULL,
  ip_address VARCHAR(45)  NULL,
  successful TINYINT(1)   NOT NULL,
  created_at DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_login_attempts_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id          CHAR(26)     NOT NULL,
  admin_id    CHAR(26)     NULL,
  action      VARCHAR(128) NOT NULL,          -- 'product.update'
  entity_type VARCHAR(64)  NOT NULL,          -- 'Product'
  entity_id   CHAR(26)     NULL,
  before_json JSON         NULL,
  after_json  JSON         NULL,
  ip_address  VARCHAR(45)  NULL,
  created_at  DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_logs_entity  (entity_type, entity_id),
  KEY ix_audit_logs_created (created_at),
  KEY ix_audit_logs_admin   (admin_id),
  CONSTRAINT fk_audit_logs_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`before` and `after` are reserved-adjacent words in SQL, so the audit columns are named `before_json` / `after_json`. The API still exposes them as `before` and `after`.

**Admin access is role-only.** An admin's permissions come from `role` and the §7.3 matrix, full stop. The `admin_brand_access` join table is gone with the second brand — there is one site, so there is nothing to grant access *to*.

### 8.4 Media

```sql
CREATE TABLE media_assets (
  id             CHAR(26)     NOT NULL,
  public_id      VARCHAR(255) NOT NULL,       -- Cloudinary public_id
  secure_url     VARCHAR(512) NOT NULL,
  type           ENUM('IMAGE','VIDEO','DOCUMENT') NOT NULL DEFAULT 'IMAGE',
  format         VARCHAR(16)  NULL,           -- jpg | png | webp | pdf
  width          INT          NULL,
  height         INT          NULL,
  bytes          INT          NULL,
  folder         VARCHAR(255) NULL,           -- 'rajdhani/products'
  alt_text       VARCHAR(255) NULL,
  caption        VARCHAR(512) NULL,
  uploaded_by_id CHAR(26)     NULL,
  created_at     DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_assets_public_id (public_id),
  KEY ix_media_assets_folder (folder),
  KEY ix_media_assets_type   (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Every image-bearing table references `media_assets` rather than storing a URL string. One place to manage alt text, one place to purge from Cloudinary on delete, and a working media library in the admin panel. Deletion checks every referencing table first and returns `409 CONFLICT` listing the dependents (§12).

### 8.5 Products

```sql
CREATE TABLE categories (
  id               CHAR(26)     NOT NULL,
  name             VARCHAR(255) NOT NULL,      -- 'Premium Tea', 'Green Tea'
  slug             VARCHAR(191) NOT NULL,
  description      TEXT         NULL,
  icon_name        VARCHAR(64)  NULL,          -- icon key for the filter bar
  image_id         CHAR(26)     NULL,
  sort_order       INT          NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  meta_title       VARCHAR(255) NULL,
  meta_description TEXT         NULL,
  created_at       DATETIME(3)  NOT NULL,
  updated_at       DATETIME(3)  NOT NULL,
  deleted_at       DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY ix_categories_active (is_active, sort_order),
  KEY ix_categories_image (image_id),
  CONSTRAINT fk_categories_image FOREIGN KEY (image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id                CHAR(26)     NOT NULL,
  category_id       CHAR(26)     NOT NULL,
  name              VARCHAR(255) NOT NULL,
  slug              VARCHAR(191) NOT NULL,
  short_description TEXT         NULL,         -- card blurb
  tagline           VARCHAR(255) NULL,
  description       MEDIUMTEXT   NULL,         -- rich text, Description tab
  ingredients       MEDIUMTEXT   NULL,
  nutrition_info    MEDIUMTEXT   NULL,
  brewing_guide     MEDIUMTEXT   NULL,
  packaging_info    MEDIUMTEXT   NULL,
  key_features      JSON         NULL,         -- string[]
  badge_text        VARCHAR(64)  NULL,         -- 'BEST SELLER'
  badge_color       VARCHAR(9)   NULL,
  status            ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  is_featured       TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order        INT          NOT NULL DEFAULT 0,
  view_count        INT          NOT NULL DEFAULT 0,
  rating_average    DECIMAL(2,1) NOT NULL DEFAULT 0.0,
  rating_count      INT          NOT NULL DEFAULT 0,
  meta_title        VARCHAR(255) NULL,
  meta_description  TEXT         NULL,
  created_at        DATETIME(3)  NOT NULL,
  updated_at        DATETIME(3)  NOT NULL,
  deleted_at        DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY ix_products_status          (status, sort_order),
  KEY ix_products_category_status (category_id, status, sort_order),
  KEY ix_products_featured        (is_featured, status, sort_order),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
  id         CHAR(26)   NOT NULL,
  product_id CHAR(26)   NOT NULL,
  media_id   CHAR(26)   NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT        NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_product_images_product (product_id, sort_order),
  KEY ix_product_images_media   (media_id),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_product_images_media   FOREIGN KEY (media_id)   REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_pack_sizes (
  id                 CHAR(26)      NOT NULL,
  product_id         CHAR(26)      NOT NULL,
  label              VARCHAR(64)   NOT NULL,  -- '250g', '500g', '1kg'
  sku                VARCHAR(64)   NOT NULL,  -- 'RPT-500'
  price              DECIMAL(10,2) NOT NULL,
  compare_price      DECIMAL(10,2) NULL,      -- struck-through original
  discount_percent   INT           NULL,
  price_includes_vat TINYINT(1)    NOT NULL DEFAULT 1,
  is_default         TINYINT(1)    NOT NULL DEFAULT 0,
  is_available       TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order         INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pack_sizes_product_label (product_id, label),
  UNIQUE KEY uq_pack_sizes_sku (sku),
  KEY ix_pack_sizes_product (product_id, sort_order),
  CONSTRAINT fk_pack_sizes_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_highlights (
  id         CHAR(26)     NOT NULL,
  product_id CHAR(26)     NOT NULL,
  title      VARCHAR(255) NOT NULL,           -- '100% Natural'
  subtitle   VARCHAR(255) NULL,
  icon_name  VARCHAR(64)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_product_highlights_product (product_id, sort_order),
  CONSTRAINT fk_product_highlights_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**SKU is now globally unique.** Under the two-brand schema a SKU could only be unique per
brand, because both brands ran their own numbering. With one site there is one SKU
namespace, so `uq_pack_sizes_sku` enforces it in the database rather than by convention.

**Rating aggregates.** `rating_average` and `rating_count` are denormalised onto `products` and recalculated inside a transaction whenever a review is approved, rejected after approval, or deleted. Listing pages never join `reviews`.

### 8.6 Reviews & Wishlist

```sql
CREATE TABLE reviews (
  id               CHAR(26)     NOT NULL,
  product_id       CHAR(26)     NOT NULL,
  customer_id      CHAR(26)     NOT NULL,
  rating           TINYINT UNSIGNED NOT NULL,     -- 1..5, enforced in the validator
  title            VARCHAR(255) NULL,
  comment          TEXT         NOT NULL,
  status           ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  moderated_by_id  CHAR(26)     NULL,
  moderated_at     DATETIME(3)  NULL,
  rejection_reason VARCHAR(512) NULL,
  created_at       DATETIME(3)  NOT NULL,
  updated_at       DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reviews_product_customer (product_id, customer_id),  -- one per customer per product
  KEY ix_reviews_product_status (product_id, status),
  KEY ix_reviews_status_created (status, created_at),
  KEY ix_reviews_customer       (customer_id),
  KEY ix_reviews_moderator      (moderated_by_id),
  CONSTRAINT fk_reviews_product   FOREIGN KEY (product_id)      REFERENCES products (id)    ON DELETE CASCADE,
  CONSTRAINT fk_reviews_customer  FOREIGN KEY (customer_id)     REFERENCES customers (id)   ON DELETE CASCADE,
  CONSTRAINT fk_reviews_moderator FOREIGN KEY (moderated_by_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wishlist_items (
  id          CHAR(26)    NOT NULL,
  customer_id CHAR(26)    NOT NULL,
  product_id  CHAR(26)    NOT NULL,
  created_at  DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wishlist_customer_product (customer_id, product_id),
  KEY ix_wishlist_product (product_id),
  CONSTRAINT fk_wishlist_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlist_product  FOREIGN KEY (product_id)  REFERENCES products (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`ix_reviews_status_created` backs the admin moderation queue, which lists pending reviews newest first across all products.

Guests may add to a local wishlist held in browser storage. On login the client posts the local list to `POST /public/wishlist/merge`, which upserts each item and returns the merged server list.

### 8.7 Dynamic Content Blocks

Everything an editor can change without a deployment (acceptance §18.2).

```sql
CREATE TABLE banners (
  id                  CHAR(26)     NOT NULL,
  placement           ENUM('HOME_HERO','ABOUT_HERO','PRODUCTS_HERO','PRODUCT_DETAIL_HERO',
                           'QUALITY_HERO','DEALER_HERO','GALLERY_HERO','NEWS_HERO','CONTACT_HERO',
                           'HOME_PROMO','HOME_VIDEO_CARD','MID_PAGE_CTA','DEALER_CTA','SIDEBAR_AD') NOT NULL,
  title               VARCHAR(255) NULL,
  title_highlight     VARCHAR(255) NULL,      -- coloured portion of the headline
  subtitle            TEXT         NULL,
  eyebrow_text        VARCHAR(255) NULL,      -- 'PREMIUM QUALITY TEA'
  desktop_image_id    CHAR(26)     NULL,
  mobile_image_id     CHAR(26)     NULL,
  video_url           VARCHAR(512) NULL,
  primary_cta_label   VARCHAR(128) NULL,
  primary_cta_url     VARCHAR(255) NULL,
  secondary_cta_label VARCHAR(128) NULL,
  secondary_cta_url   VARCHAR(255) NULL,
  overlay_opacity     INT          NULL DEFAULT 0,
  sort_order          INT          NOT NULL DEFAULT 0,
  status              ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'PUBLISHED',
  starts_at           DATETIME(3)  NULL,      -- scheduling window
  ends_at             DATETIME(3)  NULL,
  created_at          DATETIME(3)  NOT NULL,
  updated_at          DATETIME(3)  NOT NULL,
  deleted_at          DATETIME(3)  NULL,
  PRIMARY KEY (id),
  KEY ix_banners_placement (placement, status, sort_order),
  KEY ix_banners_schedule  (starts_at, ends_at),
  KEY ix_banners_desktop_image (desktop_image_id),
  KEY ix_banners_mobile_image  (mobile_image_id),
  CONSTRAINT fk_banners_desktop FOREIGN KEY (desktop_image_id) REFERENCES media_assets (id),
  CONSTRAINT fk_banners_mobile  FOREIGN KEY (mobile_image_id)  REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feature_items (
  id            CHAR(26)     NOT NULL,
  section       ENUM('HOME_USP','HOME_WHY_US','ABOUT_VALUES','ABOUT_STRENGTH',
                     'QUALITY_COMMITMENT','DEALER_BENEFITS','CONTACT_ASSURANCE',
                     'PRODUCT_HIGHLIGHTS') NOT NULL,
  title         VARCHAR(255) NOT NULL,        -- 'Made with Real Milk'
  description   TEXT         NULL,
  icon_name     VARCHAR(64)  NULL,
  icon_image_id CHAR(26)     NULL,
  icon_bg_color VARCHAR(9)   NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_feature_items_section (section, is_active, sort_order),
  KEY ix_feature_items_icon (icon_image_id),
  CONSTRAINT fk_feature_items_icon FOREIGN KEY (icon_image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE process_steps (
  id          CHAR(26)     NOT NULL,
  `group`     ENUM('FROM_GARDEN_TO_CUP','HOW_WE_MAKE_TEA','QUALITY_PROCESS',
                   'MANUFACTURING_PROCESS','BECOME_DEALER') NOT NULL,
  step_number INT          NOT NULL,          -- 1..5
  title       VARCHAR(255) NOT NULL,          -- 'Carefully Sourced'
  description TEXT         NULL,
  icon_name   VARCHAR(64)  NULL,
  image_id    CHAR(26)     NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_process_steps_group_number (`group`, step_number),
  KEY ix_process_steps_group (`group`, is_active, sort_order),
  KEY ix_process_steps_image (image_id),
  CONSTRAINT fk_process_steps_image FOREIGN KEY (image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stat_counters (
  id         CHAR(26)     NOT NULL,
  `group`    ENUM('HOME','ABOUT','GALLERY','TEA_GARDEN','DEALER_NETWORK') NOT NULL,
  value      VARCHAR(32)  NOT NULL,           -- '25+', '1000+', '100%'
  label      VARCHAR(255) NOT NULL,           -- 'Years of Experience'
  icon_name  VARCHAR(64)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_stat_counters_group (`group`, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certifications (
  id                  CHAR(26)     NOT NULL,
  name                VARCHAR(255) NOT NULL,  -- 'ISO 22000:2018'
  subtitle            VARCHAR(255) NULL,      -- 'Food Safety Management'
  logo_id             CHAR(26)     NULL,
  certificate_file_id CHAR(26)     NULL,      -- downloadable PDF
  sort_order          INT          NOT NULL DEFAULT 0,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_certifications_active (is_active, sort_order),
  KEY ix_certifications_logo (logo_id),
  KEY ix_certifications_file (certificate_file_id),
  CONSTRAINT fk_certifications_logo FOREIGN KEY (logo_id)             REFERENCES media_assets (id),
  CONSTRAINT fk_certifications_file FOREIGN KEY (certificate_file_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE testimonials (
  id          CHAR(26)     NOT NULL,
  author_name VARCHAR(255) NOT NULL,          -- 'Ahmed Hossain'
  author_role VARCHAR(255) NULL,              -- 'Distributor, Chattogram'
  avatar_id   CHAR(26)     NULL,
  quote       TEXT         NOT NULL,
  rating      TINYINT UNSIGNED NULL,
  status      ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'PUBLISHED',
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_testimonials_status (status, sort_order),
  KEY ix_testimonials_avatar (avatar_id),
  CONSTRAINT fk_testimonials_avatar FOREIGN KEY (avatar_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE page_blocks (
  id            CHAR(26)     NOT NULL,
  page_key      VARCHAR(64)  NOT NULL,        -- 'about' | 'quality' | 'dealer'
  block_key     VARCHAR(64)  NOT NULL,        -- 'our_story' | 'mission' | 'vision'
  eyebrow       VARCHAR(255) NULL,
  heading       VARCHAR(255) NULL,
  subheading    VARCHAR(255) NULL,
  body          MEDIUMTEXT   NULL,            -- rich text
  bullet_points JSON         NULL,            -- string[]
  image_id      CHAR(26)     NULL,
  cta_label     VARCHAR(128) NULL,
  cta_url       VARCHAR(255) NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  status        ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'PUBLISHED',
  PRIMARY KEY (id),
  UNIQUE KEY uq_page_blocks (page_key, block_key),
  KEY ix_page_blocks_page (page_key, status, sort_order),
  KEY ix_page_blocks_image (image_id),
  CONSTRAINT fk_page_blocks_image FOREIGN KEY (image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gallery_categories (
  id             CHAR(26)     NOT NULL,
  name           VARCHAR(255) NOT NULL,       -- 'Tea Gardens', 'Manufacturing'
  slug           VARCHAR(191) NOT NULL,
  description    TEXT         NULL,
  icon_name      VARCHAR(64)  NULL,
  cover_image_id CHAR(26)     NULL,
  sort_order     INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gallery_categories_slug (slug),
  KEY ix_gallery_categories_active (is_active, sort_order),
  KEY ix_gallery_categories_cover (cover_image_id),
  CONSTRAINT fk_gallery_categories_cover FOREIGN KEY (cover_image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gallery_images (
  id          CHAR(26)     NOT NULL,
  category_id CHAR(26)     NOT NULL,
  media_id    CHAR(26)     NOT NULL,
  title       VARCHAR(255) NULL,
  description VARCHAR(512) NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_gallery_images_category (category_id, is_active, sort_order),
  KEY ix_gallery_images_media (media_id),
  CONSTRAINT fk_gallery_images_category FOREIGN KEY (category_id) REFERENCES gallery_categories (id) ON DELETE CASCADE,
  CONSTRAINT fk_gallery_images_media    FOREIGN KEY (media_id)    REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_posts (
  id               CHAR(26)     NOT NULL,
  title            VARCHAR(255) NOT NULL,
  slug             VARCHAR(191) NOT NULL,
  excerpt          TEXT         NULL,
  content          MEDIUMTEXT   NOT NULL,     -- rich text
  cover_image_id   CHAR(26)     NULL,
  tags             JSON         NULL,         -- string[]
  status           ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  is_featured      TINYINT(1)   NOT NULL DEFAULT 0,
  published_at     DATETIME(3)  NULL,
  view_count       INT          NOT NULL DEFAULT 0,
  author_id        CHAR(26)     NULL,
  meta_title       VARCHAR(255) NULL,
  meta_description TEXT         NULL,
  created_at       DATETIME(3)  NOT NULL,
  updated_at       DATETIME(3)  NOT NULL,
  deleted_at       DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_news_posts_slug (slug),
  KEY ix_news_posts_status (status, published_at),
  KEY ix_news_posts_author (author_id),
  KEY ix_news_posts_cover (cover_image_id),
  CONSTRAINT fk_news_posts_author FOREIGN KEY (author_id)      REFERENCES admin_users (id),
  CONSTRAINT fk_news_posts_cover  FOREIGN KEY (cover_image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE downloads (
  id             CHAR(26)     NOT NULL,
  title          VARCHAR(255) NOT NULL,       -- 'Dealer Brochure'
  `key`          VARCHAR(64)  NOT NULL,       -- 'dealer_brochure'
  description    VARCHAR(512) NULL,
  file_id        CHAR(26)     NOT NULL,
  requires_email TINYINT(1)   NOT NULL DEFAULT 0,
  download_count INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_downloads_key (`key`),
  KEY ix_downloads_file (file_id),
  CONSTRAINT fk_downloads_file FOREIGN KEY (file_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`news_posts.author_id` now carries a foreign key to `admin_users`. Under the two-brand
schema it was an unconstrained `CHAR(26)`; there is no longer any reason to leave it
unenforced.

### 8.8 Lead Capture & Submissions

The platform's commercial purpose. There is no cart or checkout (§2) — business is captured here.

```sql
CREATE TABLE product_enquiries (
  id              CHAR(26)     NOT NULL,
  reference_no    VARCHAR(32)  NOT NULL,      -- 'RDFP-ENQ-2026-00841'
  product_id      CHAR(26)     NULL,
  customer_id     CHAR(26)     NULL,          -- set if submitted while logged in
  name            VARCHAR(255) NOT NULL,
  company_name    VARCHAR(255) NULL,
  phone           VARCHAR(32)  NOT NULL,
  email           VARCHAR(255) NOT NULL,
  city            VARCHAR(128) NOT NULL,
  pack_size_label VARCHAR(64)  NULL,
  quantity        VARCHAR(64)  NULL,          -- free text: '100 kg'
  message         TEXT         NOT NULL,
  status          ENUM('NEW','IN_PROGRESS','CONTACTED','CLOSED','SPAM') NOT NULL DEFAULT 'NEW',
  assigned_to_id  CHAR(26)     NULL,
  internal_notes  TEXT         NULL,
  source_page     VARCHAR(255) NULL,
  ip_address      VARCHAR(45)  NULL,
  created_at      DATETIME(3)  NOT NULL,
  updated_at      DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_enquiries_reference (reference_no),
  KEY ix_product_enquiries_status   (status, created_at),
  KEY ix_product_enquiries_created  (created_at),
  KEY ix_product_enquiries_product  (product_id),
  KEY ix_product_enquiries_customer (customer_id),
  KEY ix_product_enquiries_assignee (assigned_to_id),
  CONSTRAINT fk_enquiries_product  FOREIGN KEY (product_id)     REFERENCES products (id),
  CONSTRAINT fk_enquiries_customer FOREIGN KEY (customer_id)    REFERENCES customers (id),
  CONSTRAINT fk_enquiries_assignee FOREIGN KEY (assigned_to_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dealer_applications (
  id                  CHAR(26)     NOT NULL,
  application_id      VARCHAR(32)  NOT NULL,  -- 'RDFP-DA-2026-05120'
  full_name           VARCHAR(255) NOT NULL,
  company_name        VARCHAR(255) NOT NULL,
  phone               VARCHAR(32)  NOT NULL,
  email               VARCHAR(255) NOT NULL,
  district_id         CHAR(26)     NOT NULL,
  upazila_id          CHAR(26)     NOT NULL,
  address_line        VARCHAR(255) NULL,
  has_trade_license   TINYINT(1)   NOT NULL DEFAULT 0,
  has_tin_certificate TINYINT(1)   NOT NULL DEFAULT 0,
  years_of_experience INT          NULL,
  message             TEXT         NULL,
  status              ENUM('SUBMITTED','UNDER_REVIEW','APPROVED','REJECTED','ON_HOLD') NOT NULL DEFAULT 'SUBMITTED',
  assigned_to_id      CHAR(26)     NULL,
  internal_notes      TEXT         NULL,
  reviewed_at         DATETIME(3)  NULL,
  ip_address          VARCHAR(45)  NULL,
  created_at          DATETIME(3)  NOT NULL,
  updated_at          DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dealer_applications_app_id (application_id),
  KEY ix_dealer_applications_status   (status, created_at),
  KEY ix_dealer_applications_created  (created_at),
  KEY ix_dealer_applications_district (district_id),
  KEY ix_dealer_applications_upazila  (upazila_id),
  KEY ix_dealer_applications_assignee (assigned_to_id),
  CONSTRAINT fk_apps_district FOREIGN KEY (district_id)    REFERENCES districts (id),
  CONSTRAINT fk_apps_upazila  FOREIGN KEY (upazila_id)     REFERENCES upazilas (id),
  CONSTRAINT fk_apps_assignee FOREIGN KEY (assigned_to_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE districts (
  id            CHAR(26)     NOT NULL,
  name          VARCHAR(128) NOT NULL,        -- 'Dhaka'
  name_bn       VARCHAR(128) NULL,            -- requires utf8mb4
  division_name VARCHAR(128) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_districts_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE upazilas (
  id          CHAR(26)     NOT NULL,
  district_id CHAR(26)     NOT NULL,
  name        VARCHAR(128) NOT NULL,
  name_bn     VARCHAR(128) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_upazilas_district_name (district_id, name),
  CONSTRAINT fk_upazilas_district FOREIGN KEY (district_id) REFERENCES districts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
  id             CHAR(26)     NOT NULL,
  name           VARCHAR(255) NOT NULL,
  email          VARCHAR(255) NOT NULL,
  phone          VARCHAR(32)  NULL,
  subject        VARCHAR(255) NULL,
  message        TEXT         NOT NULL,
  status         ENUM('UNREAD','READ','REPLIED','ARCHIVED') NOT NULL DEFAULT 'UNREAD',
  replied_at     DATETIME(3)  NULL,
  internal_notes TEXT         NULL,
  ip_address     VARCHAR(45)  NULL,
  created_at     DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_contact_messages_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscribers (
  id                CHAR(26)     NOT NULL,
  email             VARCHAR(255) NOT NULL,
  is_subscribed     TINYINT(1)   NOT NULL DEFAULT 1,
  unsubscribe_token CHAR(64)     NOT NULL,
  source            VARCHAR(64)  NULL,        -- 'footer' | 'home_strip'
  subscribed_at     DATETIME(3)  NOT NULL,
  unsubscribed_at   DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_newsletter_token (unsubscribe_token),
  UNIQUE KEY uq_newsletter_email (email),
  KEY ix_newsletter_subscribed (is_subscribed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**One email, one subscriber.** `uq_newsletter_email` is now a plain unique key rather than
`(brand_id, email)`. The same person can no longer appear twice, which is what a mailing
list wants.

### 8.9 Reference Data Seeding

Seeded at deployment, idempotently — running the seeders twice must produce no duplicates and no errors:

* The single `site_profile` row, with theme colours, contact block and footer copy.
* All 64 Bangladeshi districts and their upazilas, for the dealer form dropdowns.
* One Super Admin account, password set through the invite flow (§7.2).
* Default `seo_meta` rows for every page key.
* Default `settings` rows for notification recipient lists.
* Category rows matching the product filter bar.

Seeders must be written as `INSERT … ON DUPLICATE KEY UPDATE` against the natural keys —
`site_profile.id = 1`, `districts.name`, `seo_meta.page_key`, `settings.key`,
`categories.slug` — not as "delete everything and reinsert", which would break the foreign
keys pointing at seeded rows.

### 8.10 Table Index

**36 tables.** Grouped as the admin panel presents them:

| Group | Tables |
| --- | --- |
| Site & config | `site_profile`, `settings`, `social_links`, `menu_links`, `seo_meta`, `reference_counters` |
| Accounts & auth | `admin_users`, `customers`, `refresh_tokens`, `login_attempts`, `audit_logs` |
| Media | `media_assets` |
| Catalogue | `categories`, `products`, `product_images`, `product_pack_sizes`, `product_highlights` |
| Engagement | `reviews`, `wishlist_items` |
| Content blocks | `banners`, `feature_items`, `process_steps`, `stat_counters`, `certifications`, `testimonials`, `page_blocks` |
| Gallery & news | `gallery_categories`, `gallery_images`, `news_posts`, `downloads` |
| Submissions | `product_enquiries`, `dealer_applications`, `districts`, `upazilas`, `contact_messages`, `newsletter_subscribers` |

**Changed from v2.0 (two-brand):** `brands` became the `site_profile` singleton;
`admin_brand_access` was dropped outright; `brand_id` was removed from 23 tables along with
its foreign keys; every `(brand_id, …)` unique key collapsed to its natural key; and every
`(brand_id, …)` composite index was re-cut on the columns that actually filter now. Four
constraints got *stronger* in the process — globally unique SKUs, one row per newsletter
email, one step number per process group, and a real foreign key on `news_posts.author_id`.

## 9. API Route Specification

Base URL: `https://api.rajdhanifood.com/api/v1`

Three namespaces:

| Namespace | Auth | Notes |
|---|---|---|
| `/auth/*` | Public / token | Not required |
| `/public/*` | Public, optional customer token | No additional headers |
| `/admin/*` | Admin bearer token | Role checked per route (§7.3) |

### 9.1 Standard Response Envelope

```jsonc
// Success
{ "success": true, "data": { }, "meta": { "page": 1, "limit": 12, "total": 48, "totalPages": 4 } }

// Error
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "Invalid input",
  "details": [{ "field": "email", "message": "Invalid email address" }] } }
```

**Error codes:** `VALIDATION_ERROR`, `UNAUTHENTICATED`, `TOKEN_EXPIRED`, `FORBIDDEN`, `NOT_FOUND`, `CONFLICT`, `RATE_LIMITED`, `UPLOAD_FAILED`, `INTERNAL_ERROR`.

**Conventions:** list endpoints accept `page`, `limit`, `search`, `sort`, `order`, plus resource-specific filters. All timestamps are ISO 8601 UTC.

### 9.2 Authentication Routes

| Method | Route | Auth | Description |
|---|---|---|---|
| POST | `/auth/customer/google` | Public | Exchange Google ID token for session |
| POST | `/auth/customer/refresh` | Refresh cookie | Rotate tokens |
| POST | `/auth/customer/logout` | Customer | Revoke refresh token |
| GET | `/auth/customer/me` | Customer | Current profile |
| PATCH | `/auth/customer/me` | Customer | Update phone, city, company |
| DELETE | `/auth/customer/me` | Customer | Delete account and personal data |
| POST | `/auth/admin/login` | Public (rate limited) | Email + password login |
| POST | `/auth/admin/refresh` | Refresh cookie | Rotate tokens |
| POST | `/auth/admin/logout` | Admin | Revoke refresh token |
| GET | `/auth/admin/me` | Admin | Profile and role |
| PATCH | `/auth/admin/me` | Admin | Update own name, phone, avatar |
| POST | `/auth/admin/change-password` | Admin | Requires current password |
| POST | `/auth/admin/forgot-password` | Public (rate limited) | Send reset email |
| POST | `/auth/admin/reset-password` | Public | Consume reset token |
| GET | `/auth/admin/invite/:token` | Public | Validate invite token |
| POST | `/auth/admin/invite/:token/accept` | Public | Set password, activate account |

### 9.3 Public Routes — Site Content

| Method | Route | Description |
|---|---|---|
| GET | `/public/layout` | One call returning the site profile (name, tagline, theme colours, logos), header menu, footer columns, social links and newsletter config |
| GET | `/public/seo/:pageKey` | Meta title/description/OG for a page |
| GET | `/public/banners?placement=HOME_HERO` | Active, in-window banners for a placement |
| GET | `/public/features?section=HOME_USP` | Feature/USP strip items |
| GET | `/public/process?group=FROM_GARDEN_TO_CUP` | Ordered process steps |
| GET | `/public/stats?group=HOME` | Stat counters |
| GET | `/public/certifications` | Certification badges |
| GET | `/public/testimonials` | Published testimonials |
| GET | `/public/page-blocks/:pageKey` | All editable copy blocks for a page |
| GET | `/public/home` | Aggregated home payload (banners, featured products, stats, news, testimonials) |

### 9.4 Public Routes — Products

| Method | Route | Description |
|---|---|---|
| GET | `/public/categories` | Active categories with product counts |
| GET | `/public/products` | Filter by `category`, `search`, `featured`, `sort` (`newest`, `name`, `price_asc`, `price_desc`, `rating`), paginated |
| GET | `/public/products/featured` | Home carousel set |
| GET | `/public/products/:slug` | Full detail: images, pack sizes, highlights, tab content, rating summary |
| GET | `/public/products/:slug/related` | Same category, excludes current |
| GET | `/public/products/:slug/reviews` | Approved reviews only, paginated |
| POST | `/public/products/:slug/reviews` | **Customer token required.** Creates a `PENDING` review |
| GET | `/public/downloads/:key` | Resolve brochure/catalogue URL, increment counter |

### 9.5 Public Routes — Gallery & News

| Method | Route | Description |
|---|---|---|
| GET | `/public/gallery/categories` | Filter tabs |
| GET | `/public/gallery` | Images, optional `category` filter, paginated |
| GET | `/public/news` | Published posts, paginated, optional `tag` |
| GET | `/public/news/featured` | Home "Latest Updates" set |
| GET | `/public/news/:slug` | Single post, increments view count |

### 9.6 Public Routes — Submissions

All are rate limited and protected by Google reCAPTCHA v3 plus a honeypot field.

| Method | Route | Description |
|---|---|---|
| POST | `/public/enquiries` | Product enquiry; returns `referenceNo` |
| POST | `/public/dealer-applications` | Dealer application; returns `applicationId`, submission timestamp, expected response window |
| POST | `/public/contact` | Contact form |
| POST | `/public/newsletter/subscribe` | Newsletter signup |
| GET | `/public/newsletter/unsubscribe/:token` | One-click unsubscribe |
| GET | `/public/sitemap.xml` | Sitemap, generated from published content (§14.3) |
| GET | `/public/robots.txt` | Robots file |
| GET | `/public/locations/districts` | District dropdown |
| GET | `/public/locations/districts/:id/upazilas` | Dependent upazila dropdown |

### 9.7 Public Routes — Customer Account

All require a customer access token.

| Method | Route | Description |
|---|---|---|
| GET | `/public/wishlist` | Customer's wishlist with product cards |
| POST | `/public/wishlist` | Add `{ productId }` |
| DELETE | `/public/wishlist/:productId` | Remove |
| POST | `/public/wishlist/merge` | Merge guest list after login |
| GET | `/public/my/reviews` | Own reviews with status |
| PATCH | `/public/my/reviews/:id` | Edit own review; resets status to `PENDING` |
| DELETE | `/public/my/reviews/:id` | Delete own review |
| GET | `/public/my/enquiries` | Own enquiry history |

### 9.8 Admin Routes — Dashboard & Site Profile

| Method | Route | Role |
|---|---|---|
| GET | `/admin/dashboard/summary` | All — counts of new enquiries, applications, unread messages, pending reviews, subscribers |
| GET | `/admin/dashboard/charts` | All — submissions over time, top enquired products |
| GET | `/admin/site-profile` | All — the singleton site profile |
| PATCH | `/admin/site-profile` | Super Admin — theme, contact, footer, map |
| GET/PUT | `/admin/settings` | Super Admin — key/value settings |
| GET/PUT | `/admin/seo/:pageKey` | Editor+ |
| CRUD | `/admin/social-links` | Editor+ |
| CRUD | `/admin/menu-links` | Editor+ |

### 9.9 Admin Routes — Content

Each of the following exposes the standard set: `GET /` (paginated list with filters), `POST /` (create), `GET /:id`, `PATCH /:id`, `DELETE /:id` (soft delete where applicable), and `PATCH /reorder` (bulk `sortOrder` update from drag-and-drop).

| Resource | Base path | Role |
|---|---|---|
| Categories | `/admin/categories` | Editor+ |
| Products | `/admin/products` | Editor+ |
| Product images | `/admin/products/:id/images` | Editor+ |
| Pack sizes | `/admin/products/:id/pack-sizes` | Editor+ |
| Highlights | `/admin/products/:id/highlights` | Editor+ |
| Banners | `/admin/banners` | Editor+ |
| Feature items | `/admin/features` | Editor+ |
| Process steps | `/admin/process-steps` | Editor+ |
| Stat counters | `/admin/stats` | Editor+ |
| Certifications | `/admin/certifications` | Editor+ |
| Testimonials | `/admin/testimonials` | Editor+ |
| Page blocks | `/admin/page-blocks` | Editor+ |
| Gallery categories | `/admin/gallery/categories` | Editor+ |
| Gallery images | `/admin/gallery/images` | Editor+ |
| News posts | `/admin/news` | Editor+ |
| Downloads | `/admin/downloads` | Editor+ |

Additional product operations:

| Method | Route | Description |
|---|---|---|
| PATCH | `/admin/products/:id/status` | Publish / unpublish / archive |
| PATCH | `/admin/products/:id/feature` | Toggle home-page feature flag |
| POST | `/admin/products/:id/duplicate` | Clone product with images and pack sizes |
| GET | `/admin/products/export` | CSV export |

### 9.10 Admin Routes — Reviews & Leads

| Method | Route | Role | Description |
|---|---|---|---|
| GET | `/admin/reviews` | Editor+ | Filter by `status`, `productId`, `rating` |
| PATCH | `/admin/reviews/:id/approve` | Editor+ | Approve; recalculates product aggregates |
| PATCH | `/admin/reviews/:id/reject` | Editor+ | Reject with reason |
| DELETE | `/admin/reviews/:id` | Super Admin | Hard delete |
| GET | `/admin/enquiries` | Sales, Super Admin | Filter by status, product, date range, city |
| GET | `/admin/enquiries/:id` | Sales, Super Admin | Detail |
| PATCH | `/admin/enquiries/:id` | Sales, Super Admin | Status, assignee, internal notes |
| GET | `/admin/enquiries/export` | Sales, Super Admin | CSV |
| GET | `/admin/applications` | Sales, Super Admin | Filter by status, district, date |
| GET | `/admin/applications/:id` | Sales, Super Admin | Detail |
| PATCH | `/admin/applications/:id` | Sales, Super Admin | Status transition, notes, assignee |
| GET | `/admin/applications/export` | Sales, Super Admin | CSV |
| GET | `/admin/messages` | Sales, Super Admin | Contact inbox |
| PATCH | `/admin/messages/:id` | Sales, Super Admin | Mark read/replied/archived |
| GET | `/admin/subscribers` | Sales, Super Admin | Newsletter list |
| DELETE | `/admin/subscribers/:id` | Super Admin | Remove |
| GET | `/admin/subscribers/export` | Sales, Super Admin | CSV |

Status transitions on applications and enquiries are validated server-side; illegal transitions (for example `APPROVED` → `SUBMITTED`) return `409 CONFLICT`.

### 9.11 Admin Routes — Media & Users

| Method | Route | Role | Description |
|---|---|---|---|
| POST | `/admin/media/signature` | Editor+ | Signed Cloudinary upload params |
| POST | `/admin/media` | Editor+ | Register an uploaded asset |
| GET | `/admin/media` | Editor+ | Media library, filter by folder and type |
| PATCH | `/admin/media/:id` | Editor+ | Alt text, caption |
| DELETE | `/admin/media/:id` | Super Admin / owner | Delete from Cloudinary and DB if unreferenced |
| GET | `/admin/users` | Super Admin | Admin list |
| POST | `/admin/users` | Super Admin | Create and send invite |
| PATCH | `/admin/users/:id` | Super Admin | Role and active flag |
| POST | `/admin/users/:id/resend-invite` | Super Admin | New invite token |
| DELETE | `/admin/users/:id` | Super Admin | Deactivate (never hard delete) |
| GET | `/admin/customers` | Super Admin | Registered customer list |
| PATCH | `/admin/customers/:id/block` | Super Admin | Block abusive reviewer |
| GET | `/admin/audit-logs` | Super Admin | Filter by admin, entity, date |
| POST | `/admin/cache/purge` | Editor+ | Regenerate `sitemap.xml`, purge the optional Redis cache, and trigger a prerender refresh (§14.3) |

---

## 10. Customer Website — Pages & Requirements

Route map:

| Route | Page |
|---|---|
| `/` | Home |
| `/about` | About Us |
| `/products` | Product listing with category filter |
| `/products/[slug]` | Product detail |
| `/quality` | Quality |
| `/dealer-distributor` | Dealer / Distributor |
| `/gallery` | Gallery — all |
| `/gallery/[category]` | Gallery — filtered (e.g. Tea Gardens) |
| `/news` | News listing |
| `/news/[slug]` | News article |
| `/contact` | Contact Us |
| `/wishlist` | Wishlist (auth) |
| `/account` | My account, reviews, enquiries (auth) |
| `/privacy-policy`, `/terms-conditions` | Legal (page blocks) |
| `/sitemap.xml`, `/robots.txt` | Served by the API through a web-server rewrite (§14.3) |

### 10.1 Home

- Hero: `HOME_HERO` banner — eyebrow chip, two-line headline with a coloured highlight word, subtext, primary and secondary CTA, product image. Supports multiple banners as a slider.
- USP strip below the hero: `FeatureItem` where `section = HOME_USP` (Made with Real Milk, Rich Taste, Hygienic & Safe…). This strip doubles as gallery-category quick links.
- Welcome / About teaser block: `PageBlock` (`home` / `welcome`) with a four-item benefit list and a promo card (`HOME_VIDEO_CARD`) that opens a video modal.
- Premium collection: featured products carousel with card image, name, blurb, and "View Details".
- Stats band: `StatCounter` group `HOME`, animated count-up on scroll.
- Latest updates: three most recent published news posts with date, title, excerpt.
- Newsletter strip.

### 10.2 Products & Product Detail

**Listing:** sticky category filter bar driven by `Category` (icons included), responsive grid of product cards with corner badge, name, blurb, and CTA. Filter state syncs to the URL query so results are shareable. Bulk-supply CTA block and a closing feature strip.

**Detail:**
- Breadcrumb: Home › Products › Category › Product.
- Image gallery: main image with thumbnail strip and arrow navigation, lightbox zoom.
- Title, tagline, description, star rating with approved review count, SKU of the selected pack.
- Highlight icon row (`ProductHighlight`).
- Pack size selector — selecting a size updates the SKU, price, compare price, and discount badge.
- Quantity stepper — informational only; its value is carried into the enquiry form.
- "Enquire Now" opens the enquiry modal pre-populated with the product, selected pack size, and quantity; logged-in customers get name, email, phone, and city pre-filled.
- "Download Brochure" resolves through `/public/downloads/:key`.
- Wishlist toggle and native share.
- Tabbed content: Description, Ingredients, Nutrition Information, Brewing Guide, Packaging, Reviews. Empty tabs are hidden rather than shown blank.
- Reviews tab: rating distribution, approved review list, and a submission form for logged-in customers with a note that reviews appear after approval.
- Related products.
- JSON-LD `Product` schema including aggregate rating and offers, injected via `react-helmet-async` and present in the prerendered HTML (§14.3).

### 10.3 Dealer / Distributor

Hero with benefit chips, "Why Partner With Us" cards, distribution network map with counters, a five-step "How to Become Our Dealer" timeline, a requirements checklist, and the application form (name, company, phone, email, district, dependent upazila, message). On success a modal displays the generated Application ID, submission date and time, and the expected response window, with brochure download and back-to-home actions — matching the supplied design.

### 10.4 Gallery, News, Contact, About, Quality

- **Gallery:** category tab bar (All plus each category), responsive masonry-style grid with hover captions, lightbox with keyboard navigation, closing stats band. Category pages have their own hero and breadcrumb.
- **News:** card grid with cover image, date, title, excerpt; article page renders rich text with prev/next navigation and `Article` JSON-LD.
- **Contact:** contact detail card (address, two phone numbers, two emails, website, business hours), message form, embedded map with a pin card and Google Maps link, assurance strip.
- **About:** hero, company block, Mission / Vision / Values cards, stats band, manufacturing process timeline, certifications row.
- **Quality:** hero, commitment grid, five-step quality process, certifications, and a closing assurance panel with a checklist.

### 10.5 Global Requirements

- Fully responsive at 360, 768, 1024, 1440, and 1920 px.
- Sticky header with active-route indication, mobile drawer, and Products dropdown driven by `Category`.
- Persistent "Get In Touch" CTA in the header.
- Footer generated from `menu_links`, the `site_profile` contact block, and `social_links`.
- Skeleton loaders on every async section; friendly empty states; styled 404 and 500 pages. Because the app is a client-rendered SPA, the first paint is a shell — every route must show a skeleton rather than a blank screen.
- Accessibility: semantic landmarks, keyboard-navigable menus and modals, focus trapping in dialogs, visible focus rings, alt text from `MediaAsset`, minimum AA contrast.

---

## 11. Admin Dashboard — Modules & Screens

Layout: fixed sidebar, top bar containing the current admin and logout. There is no brand switcher — one site. Navigation items render according to role.

| Module | Screens |
|---|---|
| Dashboard | Summary cards, submissions chart, recent leads, pending reviews queue |
| Products | Category list & form; product list with filters; product form in tabs — Basic, Content, Images, Pack Sizes, Highlights, SEO |
| Banners | Grid grouped by placement, drag-reorder, scheduling fields, live preview panel |
| Page Content | Page picker → editable blocks with rich text and image pickers |
| Sections | Feature items, process steps, stat counters, certifications, testimonials — each a sortable list |
| Gallery | Category manager, bulk image upload, drag-reorder, inline caption editing |
| News | Post list, TipTap editor, cover image, tags, scheduling |
| Reviews | Moderation queue with Approve / Reject, product context, bulk actions |
| Enquiries | Filterable table, detail drawer, status and assignee controls, CSV export |
| Applications | Same pattern, with district filter and status workflow |
| Messages | Inbox with read/replied/archived states |
| Subscribers | List with export and unsubscribe view |
| Media Library | Grid with folder filter, upload, alt-text editing, usage indicator |
| Downloads | Brochure and catalogue file management with download counters |
| Settings | Site profile, theme colours, logos, contact block, map coordinates, menus, social links, SEO defaults, notification recipients |
| Users | Admin list, invite flow, role editing, deactivate |
| Audit Log | Filterable activity trail with before/after diff |

Cross-cutting behaviours: optimistic updates via TanStack Query, unsaved-changes guard on forms, confirmation dialogs on destructive actions, toast notifications, server-side pagination on every table, and a "View on site" link on published content.

---

## 12. Media Handling (Cloudinary)

**Upload flow.** The dashboard requests signed parameters from `POST /admin/media/signature`, uploads directly from the browser to Cloudinary (the file never transits the API server), then registers the result with `POST /admin/media`. This keeps large uploads off PHP entirely, which matters on shared hosting where `upload_max_filesize` and `max_execution_time` are set by the host.

**Folder convention:** `rajdhani/{resource}` — for example `rajdhani/products`, `.../banners`, `.../gallery`, `.../news`, `.../certifications`, `.../documents`.

**Constraints:** images limited to 5 MB, accepted as JPG, PNG, WebP, or SVG (logos only); documents limited to 20 MB, PDF only. Uploads are validated by signature and MIME type server-side at registration.

**Delivery:** all public URLs use `f_auto,q_auto` with responsive `w_` variants built by a small Cloudinary URL helper and emitted as `srcset`. Hero images are preloaded with `<link rel="preload">`; below-the-fold images use `loading="lazy"`.

**Deletion:** removing an asset checks for references across all tables first. If referenced, the API returns `409 CONFLICT` listing the dependents rather than leaving broken images.

---

## 13. Backend Modular Architecture

```
backend/
└── api/
    │
    ├── public/                 web root — the ONLY directory the web server exposes
    │   ├── index.php           single front controller
    │   ├── .htaccess           routes everything to index.php, denies dotfiles
    │   └── uploads/            transient only; durable media lives in Cloudinary
    │
    ├── config/
    │   ├── app.php             environment, debug flag, base URL, timezone (UTC)
    │   ├── database.php        PDO DSN, charset utf8mb4, error mode = exception
    │   ├── cors.php            allowlist from env, credentials enabled
    │   ├── cloudinary.php
    │   ├── mail.php
    │   └── auth.php            token lifetimes, argon2id parameters, JWT secrets
    │
    ├── routes/
    │   ├── api.php             mounts the three namespaces
    │   ├── auth.php            /auth/*
    │   ├── public.php          /public/*    — public, optional customer token
    │   └── admin.php           /admin/*     — admin bearer token
    │
    ├── app/
    │   ├── Controllers/        HTTP only: parse, delegate, respond
    │   │   ├── Auth/           CustomerAuthController, AdminAuthController
    │   │   ├── Public/         Layout, Product, Category, Banner, Gallery, News,
    │   │   │                   Review, Wishlist, Enquiry, Dealer, Contact, Newsletter
    │   │   └── Admin/          Dashboard, SiteProfile, Product, Category, Banner, Content,
    │   │                       Gallery, News, Review, Enquiry, Dealer, Media,
    │   │                       Download, User, Customer, Settings, Audit
    │   │
    │   ├── Services/           business rules — the only layer that calls repositories
    │   │   └── Auth, SiteProfile, Product, Category, Content, Gallery, News, Review,
    │   │       Wishlist, Enquiry, Dealer, Contact, Newsletter, Media, Download,
    │   │       Location, User, Dashboard, Audit
    │   │
    │   ├── Repositories/       the only layer that issues SQL
    │   ├── Models/             row objects / entities
    │   │
    │   ├── Middleware/
    │   │   ├── Cors.php                allowlist enforced before anything else
    │   │   ├── AuthCustomer.php        validates aud="customer"
    │   │   ├── AuthAdmin.php           validates aud="admin"
    │   │   ├── RequireRole.php         §7.3 permission matrix
    │   │   ├── ValidateRequest.php
    │   │   ├── RateLimit.php
    │   │   └── AuditLog.php            before/after diff on every admin mutation
    │   │
    │   ├── Validators/         one per resource; nothing unvalidated reaches a Service
    │   │
    │   ├── Helpers/
    │   │   ├── ApiResponse.php         the §9.1 success envelope
    │   │   ├── ApiError.php            the §9.1 error envelope + error codes
    │   │   ├── Pagination.php
    │   │   ├── SlugHelper.php
    │   │   ├── ReferenceGenerator.php  gapless RDFP-ENQ / RDFP-DA (§8.2)
    │   │   ├── UlidHelper.php          CHAR(26) primary keys
    │   │   └── AuthHelper.php          JWT sign/verify, argon2id
    │   │
    │   ├── Mail/               EnquiryMail, DealerApplicationMail, ContactMail,
    │   │                       ReviewMail, AdminInviteMail  (Handlebars-style templates)
    │   │
    │   └── Jobs/               invoked by cPanel cron, not by a daemon
    │       ├── TokenCleanup.php        expired refresh tokens
    │       ├── MediaCleanup.php        orphaned Cloudinary assets
    │       ├── LeadDigest.php          weekly summary to the sales list
    │       └── SitemapBuild.php        regenerate sitemap.xml
    │
    ├── database/
    │   ├── migrations/         versioned, forward-only, never edited once applied
    │   └── seeders/            SiteProfileSeeder, DistrictSeeder, UpazilaSeeder,
    │                           AdminSeeder, CategorySeeder
    │
    ├── storage/
    │   ├── logs/               daily rotation; must be outside public/
    │   └── cache/
    │
    ├── tests/
    │   ├── Unit/
    │   └── Feature/
    │
    ├── .env                    never committed
    ├── composer.json
    └── README.md
```

**Layering contract.** Controllers handle HTTP only — parse the request, call one
service, return an envelope. Services hold business rules and are the only layer
that talks to repositories. Repositories are the only layer that issues SQL.
Nothing crosses a layer boundary sideways: a controller never touches a
repository, and a service never touches `$_REQUEST`.

**Security posture forced by the layering.** All SQL goes through PDO prepared
statements — no string interpolation into queries anywhere. With one site there is
no tenancy predicate to remember, so the entire class of bug that a brand-scope guard
existed to prevent no longer exists. What remains is ordinary SQL hygiene, enforced by
keeping every query inside a repository.

**Front controller.** `public/` is the only web-exposed directory. Everything
else — `app/`, `config/`, `storage/`, `vendor/`, `.env` — sits above it and is
unreachable over HTTP. On shared hosting this is enforced by pointing the domain's
document root at `public/`; where the host will not allow that, the fallback is an
`.htaccess` deny rule at the project root, which is weaker and must be verified.

**Reference number generation.** `RDFP-ENQ-{YYYY}-{seq}` and
`RDFP-DA-{YYYY}-{seq}`. The sequence comes from the `reference_counters` row for
that type and year, locked with `SELECT … FOR UPDATE` and incremented inside the
same transaction as the insert, so numbers are gapless and collision-free under
concurrency (§8.2).


## 14. Non-Functional Requirements

### 14.1 Performance

- Lighthouse >= 90 for Performance, Accessibility and Best Practices on the
  customer site. **SEO is treated separately — see §14.3.**
- Largest Contentful Paint under 2.5 s on a 4G connection. A client-rendered SPA
  must download and execute JavaScript before it can paint content, so this
  requires an aggressive budget: route-level code splitting, a critical bundle
  under 200 KB gzipped, and preloaded hero imagery.
- API responses under 300 ms at the 95th percentile for cached list endpoints.
- Database indexes on every foreign key and on the composite filters listed in §8.
- Aggregated payloads (`/public/home`, `/public/layout`) so a page boots in few
  round trips.
- Optional Redis layer for those aggregates, **only if the host provides Redis**
  (§5.4). Absent it, aggregates are computed per request and the index coverage in
  §8 is what keeps them inside budget.

### 14.2 Security

- Security headers set by the API and by `.htaccess` on the front-ends:
  `Content-Security-Policy` allowing Cloudinary, Google Fonts, Google Maps and
  Google Identity; `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.
- Every request body, param and query validated by a validator class — no
  unvalidated input reaches a Service.
- PDO prepared statements throughout; no SQL built by string interpolation.
- Rate limits: 100 requests per 15 minutes per IP globally; 5 per hour per IP on
  each public form; 5 per 15 minutes on admin login.
- reCAPTCHA v3 with a score threshold plus a honeypot field on all public forms.
- Rich text sanitised server-side (HTML Purifier) **before storage**.
- HTTPS enforced with HSTS; secrets in `.env`, never committed, never inside
  `public/`.
- Daily automated MySQL backups with 30-day retention and a documented restore
  procedure (§16).

### 14.3 SEO — and what the SPA costs

**This is the most significant consequence of moving from Next.js to React + Vite,
and it needs to be understood before sign-off.**

A Next.js app rendered every public page on the server, so the HTML that reached a
crawler already contained the content. A Vite SPA ships an effectively empty
`<div id="root">` and fills it with JavaScript. The practical effects:

| Consumer | Executes JavaScript? | Result without mitigation |
|---|---|---|
| Googlebot | Yes, on a deferred second pass | Indexed, but slower and less reliably than server-rendered HTML |
| Bing and other engines | Inconsistently | Content may not be indexed at all |
| Facebook, WhatsApp, LinkedIn link previews | **No** | **Shared links show no title, description or image** |

That last row is the concrete harm. For a business whose stated goal is "a strong,
SEO-friendly public presence" (§2) and whose leads arrive through shared links, a
blank WhatsApp preview is a visible failure, not a technicality.

**Mitigation — the PHP shell renderer.** The customer site's `index.html` is not
served as a flat file. A small PHP shim sits in front of it and, for every
request, injects into the HTML head before it leaves the server:

- `<title>` and `<meta name="description">` resolved from `seo_meta`, or from the
  product / news row for detail routes, with site-level fallbacks
- Open Graph and Twitter card tags, including the Cloudinary image URL
- JSON-LD: `Organization` and `WebSite` site-wide, `Product` with
  `AggregateRating` and `Offer` on product pages, `Article` on news,
  `BreadcrumbList` throughout
- `<link rel="canonical">`
- A `<noscript>` block carrying the page's primary heading and body copy

The React app then hydrates over the top and manages meta from that point on via
`react-helmet-async`. Crawlers and scrapers that never run JavaScript still get a
complete, accurate head and readable fallback content.

This shim is the reason the customer site cannot be pure static hosting; it needs
PHP on the same origin. It is small — one file plus a per-route metadata lookup
against the API — but it is **required work, not optional polish**, and it is
budgeted into Phase 5.

**Remaining requirements, unchanged:**

- `sitemap.xml` generated by the API from published content, and
  regenerated on publish (`POST /admin/cache/purge`) and nightly by cron.
- `robots.txt`, served the same way.
- Canonical URLs; trailing-slash and `www` redirects normalised at the web server.

**Honest limitation.** Even with the shim, this arrangement is weaker for SEO than
server-side rendering: content still arrives via JavaScript for engines that do
render, and the `<noscript>` block is a summary rather than the full page. If
organic search is a primary acquisition channel, that trade should be revisited
before launch. It is recorded as deviation 2 in §19.

### 14.4 Notifications

| Event | Recipients | Content |
|---|---|---|
| Product enquiry submitted | Sales list (from `settings`) | Reference number, product, contact details |
| Dealer application submitted | Sales list | Application ID, applicant, district |
| Contact message received | Contact list | Sender and message |
| Newsletter subscription | — | Welcome email to subscriber |
| Review submitted | Editor list | Product and excerpt, link to moderation queue |
| Review approved | Reviewer | Confirmation |
| Admin invited / password reset | Admin | Action link |

A mail failure must never fail the submission request. Without a queue worker on
shared hosting (§5.4), sending is done inline with a short SMTP timeout and a
try/catch: the submission is committed first, the mail attempted second, and any
failure is logged for manual retry. Where volume makes that too slow, mail is
written to a `mail_queue` table and flushed by a one-minute cron.

### 14.5 Browser Support

Latest two versions of Chrome, Edge, Firefox and Safari; iOS Safari 15+; Chrome on
Android.


## 15. Environment Configuration

**Backend — `backend/api/.env`** (never inside `public/`, never committed)

```
APP_ENV                  # local | staging | production
APP_DEBUG                # false in production
APP_URL                  # https://api.rajdhanifood.com
APP_TIMEZONE             # UTC

DB_HOST                  # localhost on cPanel
DB_PORT                  # 3306
DB_DATABASE              # cPanel prefixes this, e.g. jamunabd_rajdhani
DB_USERNAME
DB_PASSWORD
DB_CHARSET               # utf8mb4

JWT_ACCESS_SECRET        # >= 32 chars
JWT_REFRESH_SECRET       # >= 32 chars, different from the access secret
JWT_ACCESS_EXPIRY        # 15m  (customer)
JWT_REFRESH_EXPIRY       # 30d  (customer)
ADMIN_ACCESS_EXPIRY      # 20m
ADMIN_REFRESH_EXPIRY     # 7d

GOOGLE_CLIENT_ID

CLOUDINARY_CLOUD_NAME
CLOUDINARY_API_KEY
CLOUDINARY_API_SECRET

MAIL_HOST
MAIL_PORT
MAIL_USERNAME
MAIL_PASSWORD
MAIL_ENCRYPTION          # tls | ssl
MAIL_FROM_ADDRESS
MAIL_FROM_NAME

RECAPTCHA_SECRET_KEY

CORS_ORIGINS             # comma-separated allowlist
COOKIE_DOMAIN

REDIS_URL                # optional; blank disables caching entirely
LOG_LEVEL
CRON_TOKEN               # shared secret guarding cron-invoked HTTP endpoints
```

**Customer site — `frontend/customer/.env`** (Vite inlines these at build time,
so nothing secret may appear here)

```
VITE_API_BASE_URL
VITE_GOOGLE_CLIENT_ID
VITE_RECAPTCHA_SITE_KEY
VITE_CLOUDINARY_CLOUD_NAME
VITE_GTM_ID
```

**Admin dashboard — `frontend/admin/.env`**

```
VITE_API_BASE_URL
VITE_CLOUDINARY_CLOUD_NAME
```

> Everything in a `VITE_*` variable is compiled into the public bundle and is
> readable by anyone. Only publishable identifiers belong there — the Google
> **client** ID and the reCAPTCHA **site** key. Their secret counterparts live in
> the backend `.env` and never leave the server.


## 16. Deployment Plan

Target is **shared cPanel hosting**. This is a deliberate change from v1, which
specified a VPS; see deviation 4 in §19 for what it costs.

### 16.1 Topology

| Tier | Location | Notes |
|---|---|---|
| Customer site | `rajdhanifood.com` document root | Static bundle + the PHP shell renderer (§14.3) |
| Admin dashboard | `admin.rajdhanifood.com` (subdomain) | Static bundle, SPA fallback rewrite |
| Backend API | `api.rajdhanifood.com` (subdomain) | Document root points at `backend/api/public/` |
| Database | cPanel MySQL | Name and user carry the cPanel account prefix |
| Media | Cloudinary | Outside the hosting account entirely |

### 16.2 Prerequisites to confirm on the account

These decide whether this plan is viable at all, and must be checked **before**
Phase 1 starts:

1. **MultiPHP Manager** offers PHP 8.2 or 8.3.
2. **MySQL / MariaDB version** — MySQL 8, or MariaDB 10.6+ (§19 deviation 3).
3. **Subdomain allowance** covers two subdomains (`admin.` and `api.`) on top of
   the existing primary domain. The second domain is no longer needed (§6.1), so
   this is a smaller ask than v2.0 made.
4. **Document root can be set per domain** — required to point the API at
   `public/`. If the host forbids it, the fallback in §13 applies and must be
   security-reviewed.
5. **SSH / Terminal access** — determines whether Composer runs on the server or
   `vendor/` is built locally and uploaded.
6. **Cron jobs** available — the `Jobs/` directory depends on them.
7. **AutoSSL** covers all three hostnames.

### 16.3 Environments

- **Local** — PHP built-in server or Laragon/XAMPP, local MySQL 8.
- **Staging** — a `staging.` subdomain with its own database and its own
  Cloudinary folder prefix, `robots.txt` disallowing everything, and HTTP basic
  auth so it is not publicly reachable.
- **Production** — as above.

### 16.4 Deployment

- **GitHub Actions** on merge to `main`: install, lint, test, build both front-end
  bundles, then deploy. Transport is SSH/rsync where available, SFTP otherwise.
- Front-end deploys are an atomic directory swap, not an in-place file copy, so a
  half-uploaded bundle is never served.
- **Migrations** run forward-only via a CLI script over SSH, or — where there is
  no SSH — a migration endpoint guarded by `CRON_TOKEN` and disabled once applied.
  Migrations are never edited after being applied to production.
- **TLS** via cPanel AutoSSL on all three hostnames, with expiry monitored
  independently as a backstop.

### 16.5 Operations

- **Backups.** cPanel's own backups are a convenience, not a guarantee — they are
  the host's schedule on the host's storage. Additionally: a nightly cron runs
  `mysqldump`, writes off-account (Cloudinary raw storage or an object store), and
  retains 30 days. **A restore must be performed and timed once before launch, not
  merely documented.**
- **Health endpoints.** `GET /health` (liveness) and `GET /health/db` (database
  connectivity), polled by an external uptime monitor.
- **Logs.** `storage/logs/`, daily rotation, pruned by cron. Shared hosting has a
  disk quota — an unrotated log will fill it and take the site down.
- **Cron.** `TokenCleanup` daily, `MediaCleanup` weekly, `LeadDigest` weekly,
  `SitemapBuild` nightly.

### 16.6 Known limits of this target

Recorded so they are not discovered late:

- No horizontal scaling and no control over PHP worker count.
- Noisy-neighbour risk on shared CPU and I/O.
- No Redis in all likelihood, so the §14.1 cache layer stays unused.
- No queue worker, so mail is inline or cron-flushed (§14.4).
- Host-imposed caps on `max_execution_time`, `upload_max_filesize` and concurrent
  MySQL connections, none of which we control.

If traffic grows beyond what this supports, the migration path is a VPS running
the same PHP and MySQL — no application rewrite, only a change of environment.


## 17. Delivery Phases

| Phase | Deliverables | Duration |
|---|---|---|
| 1 — Foundation | Repos, front controller and routing, config and env loading, MySQL schema and migrations, seeders, both auth systems, CORS, error envelope, OpenAPI skeleton | 2 weeks |
| 2 — Core API | Products, categories, media, banners, content blocks, gallery, news, submissions, notifications, audit | 2.5 weeks |
| 3 — Admin Dashboard | Layout, auth, all CRUD modules, media library, moderation, lead management, settings, users | 2.5 weeks |
| 4 — Customer Site | All pages, forms, Google login, wishlist, reviews | 3 weeks |
| 5 — SEO & Polish | PHP shell renderer and JSON-LD (§14.3), sitemap generation, responsive QA, accessibility, performance tuning | 1.5 weeks |
| 6 — UAT & Launch | Client testing, fixes, content entry, DNS, SSL, cron, backups and a rehearsed restore, handover and training | 1.5 weeks |

**Indicative total: 13 weeks**, assuming timely client feedback and that final
copy, product photography, certification logos and brochure PDFs are supplied by
the end of Phase 3.

**Why this is 2 weeks shorter than v2.0.** Dropping the second brand (§6.1) removes
work from four phases: brand resolution and the scope guard from Phase 1, the
`brand_id` predicate on every repository method from Phase 2, the brand switcher and
brand-access UI from Phase 3, and brand-2 theming plus the cross-brand isolation audit
from Phase 5. Phase 4 is unchanged — it was only ever building one site.

**This lands back on v1's 13 weeks, but the work is not the same.** Two changes
cancelled each other out. The PHP stack *added* roughly two weeks — hand-written
repositories, migrations and seeders where Prisma generated them, plus the shell
renderer that Next.js gave for free (§14.3), plus two separate Vite builds where v1
shared more between them. Dropping the second brand then *removed* roughly two weeks.
The total matching v1 is a coincidence of arithmetic, not evidence that nothing
changed.


## 18. Acceptance Criteria

The project is accepted when:

1. The site serves its correct theme, logo, product line and contact details, all
   driven from the `site_profile` row rather than from code.
2. Every image, banner, statistic, and text block visible in the approved designs
   can be changed from the admin panel without a deployment.
3. Products are fully manageable, including multiple images, pack sizes with
   independent SKUs and prices, badges, highlights, and all detail tabs.
4. A visitor can submit a product enquiry, a dealer application, a contact
   message, and a newsletter signup; each appears in the admin panel and triggers
   an email notification.
5. The dealer application returns a unique Application ID and displays the success
   modal as designed. Reference numbers are gapless under concurrent submission.
6. A customer can sign in with Google, maintain a wishlist across devices, and
   submit a review that appears publicly only after admin approval.
7. Admin accounts are created only by a Super Admin, and the §7.3 role matrix is
   enforced by the API, not merely hidden in the UI.
8. All pages render correctly at the five specified breakpoints.
9. Lighthouse scores meet the §14.1 thresholds for Performance, Accessibility and
   Best Practices on the home, products and product detail pages.
10. **SEO acceptance (revised — see §14.3):** for the home, products, product
    detail and news article routes, the HTML returned by the server — before any
    JavaScript runs — contains the correct `<title>`, meta description, Open Graph
    tags, canonical URL and JSON-LD. Verified by fetching each URL with
    JavaScript disabled and by the Facebook and LinkedIn link-preview debuggers.
    `sitemap.xml` lists every published URL and nothing unpublished.
11. Backups, health checks and SSL are verified in production, **a database
    restore has been rehearsed and timed**, and the client team has been trained
    on the admin panel with written documentation delivered.


## 19. Open Items & Assumptions

**Assumptions**

- Domains, hosting, Cloudinary account, SMTP credentials, Google Cloud OAuth
  credentials, and reCAPTCHA keys are provided by the client.
- Final copy, product photographs, certification logos, brochure and catalogue
  PDFs are supplied by the client; the designs' placeholder content is not final.
- Prices displayed are informational; no payment processing is required.
- Analytics is Google Tag Manager, installed with a client-supplied container ID.
- English only.

**Items requiring client confirmation**

1. Whether the admin dashboard lives at `admin.rajdhanifood.com` or on a separate
   domain.
2. Whether product prices should be publicly visible at all, or hidden behind an
   enquiry.
3. Whether the "Events" and "Team" gallery categories need their own landing pages
   or remain filters only.
4. The exact recipient lists for enquiry, application, and contact notification
   emails.
5. Whether a public "News" page is required at launch, given no news design was
   supplied — the nav includes it.
6. Whether dealer brochure downloads should require an email address before the
   file is served.
7. SMTP provider preference — the client's own mail server, or a transactional
   service.
8. **Confirmation of the hosting prerequisites in §16.2** — PHP version, MySQL
   version, subdomain allowance, per-domain document root, SSH access, cron.
   Items 1 and 4 in that list can invalidate this deployment plan outright.
9. **Confirmation that the second brand is genuinely out of scope, not deferred.**
   §6.2 sets out what reintroducing it costs once there is production data. If
   Rajdhani Milk Added Tea is a real plan for later, say so before Phase 1 — the
   two-brand schema is far cheaper to build than to retrofit.

---

**Agreed deviations from this document**

Changes agreed after the original sign-off. Recorded here rather than applied
silently, so the document and the build stay reconcilable at acceptance.

| # | Section | Agreed change | Date |
|---|---|---|---|
| 1 | §5.3 | ORM pinned to Prisma 6 (was Prisma 5). **Superseded by deviation 2** — the Node stack was withdrawn before implementation continued. | 2026-09-06 |
| 2 | §4, §5, §8, §13 | **Backend stack changed from Node/Express/Prisma/PostgreSQL to PHP 8.2/8.3 + MySQL 8, and the customer site from Next.js to React + Vite.** Driven by the decision to deploy on shared cPanel hosting, which supports neither Node processes nor PostgreSQL. Cost: server-side rendering is lost and replaced by the PHP shell renderer in §14.3; timeline grew from 13 to 15 weeks (§17), later reduced again by deviation 5. The data model, API surface and permission matrix were unchanged. | 2026-09-06 |
| 3 | §5.3, §8 | Database stated as **MySQL 8**; MariaDB 10.6+ is an acceptable substitute, since cPanel commonly ships MariaDB. The schema uses no MySQL-8-only syntax. To be confirmed against the account (§16.2 item 2). | 2026-09-06 |
| 4 | §16 | Deployment target changed from **VPS running Ubuntu 24.04** to **shared cPanel hosting**. Consequences are enumerated in §16.6: no root, no Docker, no queue worker, probably no Redis, host-controlled PHP limits. | 2026-09-06 |
| 5 | §2, §4, §6, §7, §8, §9, §11, §12, §13, §16, §17, §18 | **The second brand is removed; this is now a single-site platform.** The red/gold designs for Rajdhani Milk Added Tea were supplied in error and that brand was never in scope. Removed: the `brands` table (replaced by the `site_profile` singleton), `admin_brand_access`, `brand_id` and its foreign key on 23 tables, every `(brand_id, …)` composite key and index, the `X-Brand` header, `ResolveBrand`, `RequireBrandAccess`, the `BRAND_REQUIRED` and `BRAND_FORBIDDEN` error codes, the host-to-brand resolver, the admin brand switcher, the `rajdhanitea.com` domain, and the cross-brand isolation test suite. The schema drops from 37 tables to 36 and the timeline from 15 weeks to 13 (§17). Four constraints became *stronger*: globally unique SKUs, one newsletter row per email address, one step number per process group, and a real foreign key on `news_posts.author_id`. **§6.2 records what reversing this would cost.** | 2026-09-07 |

---

**Open technical questions raised during the build**

1. **Gapless reference numbers.** §13 asks for a per-year sequence that
   is *gapless*. A native auto-increment or sequence cannot be gapless — a
   rolled-back insert permanently burns its value. The implementation uses a
   `reference_counters` row locked with `SELECT … FOR UPDATE` inside the insert
   transaction (§8.2), which is gapless and collision-free. Confirmation welcome:
   if gaps are in fact acceptable, a simpler mechanism will do.
2. **Is organic search a primary acquisition channel?** §14.3 documents an honest
   loss of SEO strength versus the original server-rendered design. If organic
   search matters more than assumed, the shell renderer may not be sufficient and
   the rendering strategy should be revisited before Phase 4 begins.

**Change control.** Anything not specified in this document is a change request,
estimated separately and scheduled after the current phase.

---

*Rajdhani Tea Platform Requirements — v3.0 (single-site, PHP / MySQL / React+Vite),
2026-09-07. Superseded versions are retained alongside this file: v2.0 (two-brand) as
`Rajdhani_Project_Doc.v2-multibrand.md.bak`, and v1.0 (Node / Prisma / PostgreSQL /
Next.js) as `Rajdhani_Project_Doc.v1-node-stack.md.bak`.*
