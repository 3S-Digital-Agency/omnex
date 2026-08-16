# OMNEX

A single control plane for a company's entire digital infrastructure:
**domains · DNS · hosting · cloud · websites · storage · email · security ·
billing · AI · automation · DevOps**.

> **OMNEX** — Cloud Infrastructure, Simplified.

---

## Repository layout

```
backend/   Laravel 11 — OMNEX CORE (modular monolith, multi-tenant, API-first)
frontend/  React + TypeScript + Vite — the OMNEX Command Center (SPA + PWA-ready)
infra/     docker-compose for PostgreSQL + Redis
docs/      Phase 0 living documentation (architecture, database, API, security, roadmap)
```

Full architecture, database map, API map, security model and roadmap live in
[`docs/`](docs/). Read [`docs/architecture.md`](docs/architecture.md) first.

---

## Quickstart

### 1. Try the frontend immediately (no backend required)

The frontend ships with an **in-browser mock** (default), so you can click
through the whole MVP with zero infrastructure:

```bash
cd frontend
pnpm install
pnpm dev          # http://localhost:5173
```

Demo account: `demo@omnex.dev` / `password`.
In mock mode, the in-memory state resets on reload.

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

## Phase 1 — Definition of Done

A user can:

1. ✅ create an account
2. ✅ create an organization (becomes owner)
3. ✅ invite a user (by email)
4. ✅ assign a role (Owner / Admin / Developer / Viewer)
5. ✅ log in with MFA (TOTP + recovery codes)
6. ✅ see the Command Center dashboard
7. ✅ view the audit log

---

## Security invariants (non-negotiable)

- **Multi-tenant from day one**: global scope + PostgreSQL RLS (defense-in-depth,
  opt-in via `NEXUS_ENFORCE_RLS` until the RLS suite passes) + tenant-scoped
  storage namespaces (Phase 4).
- **Default-deny authorization**: every request resolves an active organization
  and every route requires a permission or a policy.
- **Audit**: all critical mutations are recorded before/after, immutable.
- **MFA**: RFC 6238 TOTP implemented in-house (no third-party MFA dependency);
  verified against RFC test vectors.

See [`docs/security.md`](docs/security.md) for the full threat model and the
cross-tenant attack test checklist.

---

## Verification status

| Layer | Status |
|---|---|
| Frontend typecheck (`pnpm typecheck`) | ✅ green |
| Frontend tests (`pnpm test`) | ✅ 14/14 |
| Frontend build (`pnpm build`) | ✅ green |
| Backend (`composer install` + `php artisan test`) | ✅ 39/39 |

The Laravel backend is validated against a local portable PHP + PostgreSQL
setup (see `infra/dev-env.sh`); `php artisan test` runs the full Pest suite.
