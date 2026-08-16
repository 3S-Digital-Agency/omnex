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
| 5+ | Sites, Billing, Security, Cloud, CI/CD, Mail, AI… | 🔜 Planned |

Phase 4 is delivered: `StorageProviderInterface` with a deterministic
**sandbox** and a real **S3** provider (AWS SigV4 over Guzzle — AWS S3,
Cloudflare R2, MinIO, OVH), plus the full drive lifecycle (folders, files,
versions, trash, quotas, signed URLs) behind `StorageService`, a REST API
and the OMNEX Drive UI.

---

## What's inside

### 🔐 IAM & multi-tenant foundation (Phase 1)
- Accounts, organizations, invitations, roles/permissions (Owner / Admin /
  Developer / Viewer), org switching.
- **MFA** — RFC 6238 TOTP implemented in-house + recovery codes.
- **Social login (GAFAM)** — Google, Microsoft (OpenID), Apple (JWT ES256),
  Facebook, Amazon, behind `SocialAuthProviderInterface`; sandbox works with
  zero credentials, real providers activate via `.env`.
- Every request resolves an active organization; all tenant queries are
  scoped automatically; **default-deny** authorization; immutable audit log.

### 🖥️ Command Center (Phase 2)
- Live dashboard, activity feed, notifications, security score, Ctrl+K
  command palette, full **EN/FR** i18n (browser-language detection on first
  launch, explicit choice persisted).

### 🌐 Domain + DNS engine (Phase 3)
- Search / availability / register / renew / transfer behind
  `DomainProviderInterface` — **multi-provider**: sandbox, **Namecheap**
  (XML API), **OVHcloud** (signature auth + cart flow) and a generic
  **Custom** JSON HTTP registrar, selectable per request.
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
docker exec -it nexus-postgres createdb -U nexus nexus_test
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
| `SocialAuthProviderInterface` | sandbox · Google · Microsoft · Apple · Facebook · Amazon |
| `DnsPropagationCheckerInterface` | sandbox (deterministic) |

---

## Security invariants (non-negotiable)

- **Multi-tenant from day one**: global scope + PostgreSQL RLS (defense-in-depth,
  opt-in via `NEXUS_ENFORCE_RLS`) + tenant-scoped storage namespaces.
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
| Frontend tests (`pnpm test`) | ✅ 24/24 |
| Frontend build (`pnpm build`) | ✅ green |
| Backend (`php artisan test`) | ✅ 105/105 (361 assertions) |

The Laravel backend is validated against a local portable PHP + PostgreSQL
setup (see `infra/dev-env.sh`); `php artisan test` runs the full Pest suite.
