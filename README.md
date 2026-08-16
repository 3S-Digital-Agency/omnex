# OMNEX

**Cloud Infrastructure, Simplified.**

A single control plane for a company's entire digital infrastructure —
**domains · DNS · websites · cloud · storage · email · security · billing ·
AI · automation · DevOps**. Every resource becomes an object of the system,
managed from one interface, behind one identity, one security model and one
API.

> 🎬 **Pitch & roadmap** — see the
> [slide deck](frontend/public/presentation.html) (`pnpm dev` → `/presentation.html`)
> or the portable [`OMNEX-Presentation.md`](OMNEX-Presentation.md).

---

## Status

| Phase | Module | Status |
|---|---|---|
| 0 | Discovery (architecture, database, API, security, roadmap) | ✅ Done |
| 1 | Foundation — IAM, organizations, RBAC, audit, MFA, social login | ✅ Delivered |
| 2 | Command Center — dashboard, activity, Ctrl+K, i18n (EN/FR) | ✅ Delivered |
| 3 | Domain + DNS engine — registry, DNS, DNSSEC, propagation | ✅ Delivered |
| 4 | OMNEX Drive — storage abstraction (S3-compatible) | ✅ Delivered |
| 5 | OMNEX Sites — Git deploys, rollback, encrypted env vars | ✅ Delivered |
| 6 | Billing — plans, subscriptions, invoices, Stripe + sandbox | ✅ Delivered |
| 7 | Security — findings engine, score, dismiss/reopen | ✅ Delivered |
| 8+ | Cloud, CI/CD, Mail, AI… | 🔜 Planned |

Phase 6 is delivered: `PaymentProviderInterface` with a deterministic **sandbox**
and a **Stripe** adapter (hosted Checkout Sessions + HMAC-verified webhooks);
plans catalog, tenant-scoped subscriptions and invoices, idempotent webhook
handling, audit + owner notifications, and the OMNEX Billing UI.

---

## What's inside

### 🔐 IAM & multi-tenant foundation (Phase 1)
- Accounts, organizations, invitations, roles/permissions (Owner / Admin /
  Developer / Viewer), org switching.
- **MFA** — RFC 6238 TOTP implemented in-house + recovery codes.
- **Social login (GAFAM + sovereign)** — Google, Microsoft (OpenID), Apple
  (JWT ES256), Facebook, Amazon, **GitHub** (OAuth2), **OpenAI** (OIDC) and
  **Serveurs du Peuple** (Nextcloud OAuth2), behind
  `SocialAuthProviderInterface`; sandbox works with zero credentials, real
  providers activate via `.env`.
- Every request resolves an active organization; all tenant queries are
  scoped automatically; **default-deny** authorization; immutable audit log.

### 🖥️ Command Center (Phase 2)
- Live dashboard, activity feed, notifications, security score, Ctrl+K
  command palette, full **EN/FR** i18n (browser-language detection on first
  launch, explicit choice persisted).
- **Notification bell** in the header: unread badge, read/unread states,
  per-item + mark-all-as-read, severity icons (info/success/warning/danger),
  clickable notifications that deep-link to the related resource, and
  **real-time delivery over Server-Sent Events** (`/notifications/stream`)
  with a polling fallback.
- A merged **Notifications & Activity** page: notifications with type / severity /
  read-state filters and pagination, alongside the live activity feed — both
  update in real time without a reload.
- **Real-time transport is swappable**: an in-memory `StreamBroker` for local
  dev/tests and a **Redis pub/sub** broker (`OMNEX_STREAM_DRIVER=redis`) so
  events published by one PHP worker reach SSE subscribers on another — the
  requirement for horizontal scaling.

### 🌐 Domain + DNS engine (Phase 3)
- Search / availability / register / renew / transfer behind
  `DomainProviderInterface` — **multi-provider**: sandbox, **Namecheap**
  (XML API), **OVHcloud** (signature auth + cart flow) and a generic
  **Custom** JSON HTTP registrar, selectable per request. **Custom** is a
  gateway port for wiring any registrar/hosting you control (your own
  HTTP/JSON proxy in front of a reseller or a self-hosted platform) — not a
  plan to become a registrar.
- Full DNS record CRUD (A, AAAA, CNAME, MX, TXT, NS, SRV, CAA) with
  validation, templates, BIND zone-file import/export, immutable history +
  reversible rollback.
- **DNSSEC** (enable/disable + DS records) and **per-nameserver propagation
  monitoring**, both behind provider abstractions.

### 💾 OMNEX Drive (Phase 4)
- `StorageProviderInterface` with sandbox + S3-compatible providers
  (AWS SigV4 over Guzzle — R2 / MinIO / OVH Object Storage).
- Folders, files, versioning, trash/restore, per-organization quota,
  signed download/upload URLs, audit trail, RBAC (`storage.read` /
  `storage.manage`).
- Rule: **no Nextcloud/ownCloud/Seafile by default** — OMNEX owns its storage
  abstraction and its own cloud UI.

### 🧱 OMNEX Sites (Phase 5)
- `SiteProviderInterface` with **sandbox** (deterministic) + **Custom**
  (HTTP/JSON hosting gateway) providers, selectable per request.
- Provision sites from Git (static / Laravel / Next), deploy builds, list
  deployments and build logs, roll back to any previous live release.
- **Failed deploy → automatic rollback** to the previous live deployment.
- Environment variables are encrypted at rest and **never returned by the
  API** — only their key names are exposed (`sites.read` / `sites.manage`).

### 💳 OMNEX Billing (Phase 6)
- `PaymentProviderInterface` with **sandbox** (deterministic) + **Stripe**
  (hosted Checkout Sessions, webhook signature verified with HMAC-SHA256).
- Plans catalog, tenant-scoped **subscriptions** and **invoices**, subscribe →
  webhook-verified activation (paid invoice + plan tier) or failure path
  (`past_due`), idempotent webhook redelivery, cancel.
- Every mutation is audited and payment outcomes notify organization owners
  (`billing.read` / `billing.manage`).
- **Coupons** (percent/amount, expiry, redemption caps — applied at checkout,
  Stripe `discounts[0][coupon]`, `omnex:stripe-sync-coupons`), **credits**
  (signed ledger applied against invoices) and **proration** (unused period of
  a plan change becomes credit). Invoices break down `amount` / `discount` /
  `credit_applied` / `amount_due`.

### 🛡️ Security Center (Phase 7)
- Findings engine behind the live score: MFA, unverified email, single-member
  organization, expiring domains, DNSSEC off — each with severity, impact and
  remediation, dismissible/reopenable (`security.read` / `security.manage`).
- Score recomputed on demand (`scan`); dashboard score and Security Center are
  both driven by the same API.

---

## Repository layout

```
backend/   Laravel 12 — OMNEX CORE (modular monolith, multi-tenant, API-first)
frontend/  React + TypeScript + Vite — the OMNEX Command Center (SPA + PWA)
infra/     portable dev env (PHP/PG/Redis) + docker-compose
docs/      Phase 0 living documentation (architecture, database, API, security, roadmap)
OMNEX-Presentation.md   one-page pitch (portable Markdown)
```

Read [`docs/architecture.md`](docs/architecture.md) first for the guiding
principles, then [`docs/roadmap.md`](docs/roadmap.md) for the full plan.

---

## Quickstart

### 1. Try the frontend immediately (no backend required)

The frontend ships with an **in-browser mock** (default), so you can click
through the whole platform with zero infrastructure:

```bash
cd frontend
pnpm install
pnpm dev          # http://localhost:5173
```

Demo account: `demo@omnex.dev` / `password`. In mock mode, in-memory state
resets on reload.

### 2. Run the real stack (Laravel + PostgreSQL + Redis)

Prerequisites: PHP 8.2+, Composer, and Docker (or local PostgreSQL 16 + Redis 7).

```bash
# data services
docker compose -f infra/docker-compose.yml up -d

# backend
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan test            # Pest suite (incl. cross-tenant isolation)
php artisan serve           # http://localhost:8000

# frontend against the real API
cd ../frontend
cp .env.example .env
# set VITE_USE_MOCKS=false in .env
pnpm install
pnpm dev
```

Demo accounts (seeded): `demo@omnex.dev` and `dev@omnex.dev`, both `password`.

For the test suite, create the test database first:

```bash
docker exec -it omnex-postgres createdb -U omnex omnex_test
```

---

## Provider abstractions (ports & adapters)

Every external system sits behind an interface — the provider is **data, not
code**. Swap a registrar, DNS or storage backend without touching module code:

| Interface | Providers |
|---|---|
| `DomainProviderInterface` | sandbox · Namecheap · OVHcloud · Custom |
| `DnsProviderInterface` | sandbox (real provider via the same port) |
| `StorageProviderInterface` | sandbox · S3-compatible (R2 / MinIO / OVH) |
| `SocialAuthProviderInterface` | sandbox · Google · Microsoft · Apple · Facebook · Amazon · Serveurs du Peuple |
| `DnsPropagationCheckerInterface` | sandbox (deterministic) |

---

## Security invariants (non-negotiable)

- **Multi-tenant from day one**: global scope + PostgreSQL RLS (defense-in-depth,
  opt-in via `OMNEX_ENFORCE_RLS`) + tenant-scoped storage namespaces.
- **Default-deny authorization**: every request resolves an active organization
  and every route requires a permission or a policy.
- **Audit**: all critical mutations are recorded before/after, immutable.
- **MFA**: RFC 6238 TOTP implemented in-house (no third-party MFA dependency);
  verified against RFC test vectors.
- **AI by permission, not by default** (future phases).

See [`docs/security.md`](docs/security.md) for the full threat model and the
cross-tenant attack test checklist.

---

## Verification status

| Layer | Status |
|---|---|
| Frontend typecheck (`pnpm typecheck`) | ✅ green |
| Frontend tests (`pnpm test`) | ✅ 38/38 |
| Frontend build (`pnpm build`) | ✅ green |
| Backend (`php artisan test`) | ✅ 166 passed + 1 skipped (626 assertions) |

The Laravel backend is validated against a local portable PHP + PostgreSQL
setup (see `infra/dev-env.sh`); `php artisan test` runs the full Pest suite.
