# OMNEX — Architecture Map

> Phase 0 deliverable. This document is the single source of truth for how
> OMNEX is structured. It is reviewed and updated as the system evolves.

---

## 1. Guiding principles

1. **Laravel is the core, not the whole.** Business logic lives in the backend
   only. The React frontend renders state and sends intents — it never decides.
2. **Modular monolith first.** At 10 users, microservices are a tax, not a
   benefit. OMNEX starts as one deployable Laravel application with hard module
   boundaries, so it can be split later **without a rewrite**.
3. **Ports & adapters for every external system.** Providers (registrars, DNS,
   storage, cloud, email, payments) are behind interfaces. A provider can be
   added or removed without touching module internals.
4. **Events for cross-module communication.** Modules talk through an event bus,
   never by reaching into each other's tables.
5. **API-first.** Every capability is exposed through a versioned REST API with
   OpenAPI. The React app is just one API consumer.
6. **Tenant isolation is structural, not conventional.** See `security.md`.

---

## 2. High-level view

```
                        OMNEX
                          │
                  React / TypeScript (SPA + PWA)
                          │
                    HTTPS (REST + WS)
                          │
                   Laravel API  /api/v1/*
                          │
                 ┌────────┼────────────────────────────┐
                 │        │                            │
              Modules   Event Bus                 Auth/IAM
                 │     (Events+Queues)            (Sanctum)
                 │        │                            │
                 └────────┴──────────────┬─────────────┘
                                         │
                          ┌──────────────┼──────────────┐
                          │              │              │
                     PostgreSQL        Redis        S3-compatible
                     (system of        (cache,       (objects,
                      record)           queues)       tenant namespaced)
```

There is **no separate API gateway service** in the MVP. Laravel's router is the
gateway (single entry point, auth, rate limiting, versioning). A dedicated
gateway is only justified once OMNEX has multiple backend services — that
decision is deferred and documented here so it is a conscious one.

---

## 3. Repository layout (monorepo)

```
omnex/
├── backend/                # Laravel 11+, PHP 8.3 — OMNEX CORE
│   ├── app/
│   │   ├── Modules/        # one directory per domain module
│   │   │   ├── Iam/
│   │   │   ├── Organizations/
│   │   │   ├── Domains/
│   │   │   ├── Dns/
│   │   │   ├── Sites/
│   │   │   ├── Storage/
│   │   │   ├── Billing/
│   │   │   ├── Cloud/
│   │   │   ├── Email/
│   │   │   ├── Security/
│   │   │   ├── Backups/
│   │   │   ├── Deploy/
│   │   │   ├── Observe/
│   │   │   ├── Automate/
│   │   │   ├── Ai/
│   │   │   └── Audit/
│   │   └── Support/        # cross-cutting: events, providers, helpers
│   ├── routes/api.php
│   ├── database/migrations/
│   └── tests/
├── frontend/               # React 18+ / TypeScript / Vite
│   ├── src/
│   │   ├── app/            # router, providers, query client
│   │   ├── features/       # one directory per domain (mirrors modules)
│   │   ├── components/     # design system
│   │   └── lib/            # API client (generated from OpenAPI)
│   └── ...
├── docs/                   # Phase 0+ living documentation
├── infra/                  # docker-compose, later IaC (Terraform)
└── packages/               # shared TS types / OpenAPI schema (contracts)
```

Only the modules that exist in a phase are created in `app/Modules`. Phase 1
creates `Iam`, `Organizations`, `Audit`, `Notifications` and the supporting
shell. No placeholder code for future phases.

---

## 4. Module boundaries

Each module owns:

- its **domain model** (entities, value objects)
- its **migrations** for its own tables
- its **HTTP controllers** (mounted under `/api/v1/<module>`)
- its **service layer** (the only code controllers call)
- its **policies** (authorization)
- its **events** (what it emits) and **listeners** (what it reacts to)
- its **provider interface** (port) + adapters

**Rules:**

- A module may depend on `Iam` (for the current user/tenant context) and on
  shared `Support` code. Nothing else.
- A module must **never** read or write another module's tables directly. If it
  needs data, it uses a defined service interface or an event.
- Controllers are thin (validation + auth + call service + return resource).
  Services hold the logic. Providers are only reachable through services.

---

## 5. Core module: IAM + Organizations (Phase 1)

```
User ──< Membership >── Organization
              │
              ├── Team (optional grouping)
              └── Role ──< RolePermission >── Permission
                                             │
                                             └── Resource scope
```

- A user belongs to one or more organizations via `Membership`.
- Every request carries an **active organization** (tenant context), resolved
  from the token/session. All tenant queries are scoped by it automatically.
- Permissions are granted on resources scoped to the organization (and
  optionally a team). See `security.md` for the full model.

---

## 6. Provider abstraction (ports & adapters)

Every external dependency is behind a PHP interface. Example (Storage, Phase 4):

```php
interface StorageProviderInterface
{
    public function put(string $key, $stream, array $options): StorageObject;
    public function get(string $key): StorageObject;
    public function delete(string $key): void;
    public function exists(string $key): bool;
    public function signedDownloadUrl(string $key, int $ttl): string;
    public function signedUploadUrl(string $key, int $ttl): string;
    public function list(string $prefix): iterable;
}
```

Adapters: `S3Provider`, `R2Provider`, `OVHProvider`, `MinIOProvider`.
All implement the same interface; they differ only in endpoint, auth, and any
provider-specific quirks (kept inside the adapter, never leaked to services).

The active provider is **data, not code**: a `providers` table records the
driver + credentials per organization (or per resource), referenced via a
service-locator registry. Adding a provider = new adapter class + factory entry.

The same pattern applies to: `DomainProviderInterface`, `DnsProviderInterface`,
`CloudProviderInterface`, `EmailProviderInterface`, `PaymentProviderInterface`.

---

## 7. Event bus

Modules communicate through typed domain events dispatched on the Laravel event
bus, processed in queue workers (Redis). Examples from the spec:

```
DomainRegistered, DomainRenewed, DomainExpiring, SSLExpiring,
PaymentFailed, WebsiteCreated, DeploymentStarted, DeploymentFailed,
DeploymentCompleted, BackupCompleted, SecurityThreatDetected, UserInvited
```

Conventions:

- Events are **facts in the past tense** (`PaymentFailed`, not `PayInvoice`).
- Events carry IDs and minimal payloads; listeners re-fetch what they need
  through the owning module's service — never from another module's tables.
- Critical listeners run in a dedicated queue so a slow handler (e.g. DNS
  propagation check) never blocks billing.
- Cross-module side effects (audit, notifications) are listeners, not inline
  calls in the emitter.

---

## 8. Frontend architecture

- **Vite + React + TypeScript**, `react-router` for routing.
- **TanStack Query** for all server state — cache key convention
  `['module', 'resource', id]`, optimistic updates only where safe.
- **Tailwind CSS** + an accessible component system (design tokens first).
- **API client generated from the OpenAPI spec** so the contract is never
  hand-synced.
- **WebSockets** (Laravel Reverb or Pusher) for real-time Command Center data.
- **PWA** with offline shell; command palette (`Ctrl + K`) in Phase 2.
- The frontend mirrors backend modules (`features/domains`, `features/storage`,
  ...) but contains **zero business rules** — only presentation + API calls.

---

## 9. Explicit non-goals (MVP)

- No microservices, no separate auth/API-gateway service.
- No multi-region, no read replicas (Phase 14).
- No per-tenant database/schema (see `database.md`).
- No custom SMTP server (Email uses specialized providers).
- No simulated domain availability in production (Domains integrate a real
  registrar once connected; tests use a fake provider, never "simulated" data).
- **No Nextcloud, ownCloud or Seafile by default.** OMNEX owns its Storage
  abstraction (`StorageProviderInterface`) and its own Cloud UI ("My Cloud").
  A third-party engine is integrated only if it demonstrates a concrete
  technical advantage, and it can never become the System of Record.
