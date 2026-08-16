# NEXUS — Technical Roadmap

> Phase 0 deliverable. Ordered, one-domain-at-a-time. Each phase has a
> Definition of Done; a phase is not started until the previous one passes the
> Quality Gate (architecture, security, performance, UX, business).

---

## Status

- **Phase 0 — Discovery: DONE** (this document set).
- **Phase 1 — Foundation: IMPLEMENTED** (backend source complete; frontend
  verified with typecheck/tests/build; backend runnable via docker-compose +
  `php artisan test` on a machine with PHP/Docker).
- **Phase 2 — Command Center: IMPLEMENTED** (global nav, module cards for
  Domains/Sites/Cloud/Storage/Security/Billing, live activity feed via
  incremental `/activity` polling, Ctrl+K command palette, Security Score
  teaser). WebSockets (Reverb) replace polling once the backend runtime exists.
- **Phase 3 — Domain + DNS: IMPLEMENTED** (Domain/DNS engines behind
  `DomainProviderInterface`/`DnsProviderInterface`; sandbox registrar; search,
  register, renew, transfer; full DNS record CRUD with validation, templates,
  zone-file import/export, immutable history + rollback; expiration scheduler;
  events → audit stream). DNSSEC + propagation monitoring + a real registrar
  are the remaining steps.

---

## Phase 0 — Discovery ✅

Deliverables produced: `architecture.md`, `database.md`, `api.md`,
`security.md`, `roadmap.md`.

**Environment finding (blocker for backend runtime, see §4):** this workspace
has Node 24 / npm / pnpm but **no PHP, no Composer, no Docker, no PostgreSQL,
no Redis**. Laravel cannot be run, migrated, or tested here until one of those
is available. The frontend and the API contract can proceed regardless.

---

## Phase 1 — NEXUS Foundation

**Goal:** a real, authenticated, multi-tenant skeleton with RBAC and audit.

Scope:

- Laravel 11+ app with PostgreSQL + Redis (via Docker Compose).
- Modular structure (`app/Modules`), `Support`, port/registry shell.
- Auth: register, login, logout, email verification, MFA (TOTP), Sanctum SPA
  + API tokens, session/device management.
- Organizations: create, memberships, invitations, teams, roles, permissions
  (custom RBAC on the model above).
- Tenant isolation: global scope + RLS + namespace + tests.
- Audit + notifications (seed channels).
- API v1 for the above + OpenAPI spec.
- React frontend: Vite + TS + Tailwind + design system + TanStack Query +
  generated client, auth flow, org switcher, member/role management.
- CI: lint, format, typecheck, PHPStan/Pint, tests, `composer audit`, `npm audit`.

**Definition of Done (from spec):** a user can (1) create an account,
(2) create an organization, (3) invite a user, (4) assign a role, (5) log in
with MFA, (6) see their dashboard, (7) view their audit log.

**Quality Gate focus:** tenant-isolation tests green; no endpoint without
authorization; audit covers all mutations.

---

## Phase 2 — NEXUS Command Center

Dashboard with real-time overview, navigation, `Ctrl + K` command palette.
Modules: Overview, Domains, Sites, Cloud, Storage, Security, Billing, Activity.
Real-time via WebSockets; aggregated activity feed from the event bus.

**DoD:** real-time dashboard reflecting real backend events; global nav;
keyboard-first command palette.

---

## Phase 3 — Domain + DNS

Domain Engine + NEXUS DNS behind `DomainProviderInterface` /
`DnsProviderInterface`. Search, register, renew, transfer, contacts, privacy,
locking, nameservers; A/AAAA/CNAME/MX/TXT/NS/SRV/CAA, DNSSEC, templates,
validation, import/export, history, rollback, propagation monitoring.

**DoD:** full DNS zone CRUD + history/rollback on a **test/sandbox provider**
first; expiration monitoring produces real events; a real registrar is
connected only after the sandbox path is proven (no simulated availability).

**Delivered:** sandbox registrar + DNS providers, `DomainService`/`DnsService`,
validation (A/AAAA/CNAME/MX/TXT/NS/SRV/CAA), BIND zone-file import/export,
DNS templates, immutable `dns_history` with reversible rollback, expiration
command + scheduler, `Domain*`/`DnsRecordChanged` events feeding the audit
stream, and a React UI (domains list + search/register + domain detail with
DNS records/history/rollback/import/export). **Remaining:** DNSSEC, propagation
monitoring, and a real registrar provider.

---

## Phase 4 — NEXUS Storage (Drive)

`StorageProviderInterface` with `S3Provider`, `R2Provider`, `OVHProvider`,
`MinIOProvider`. Upload/download via signed URLs, folders, sharing,
permissions, quotas, versioning, trash, search, previews, favorites, recent.

**Rule:** no Nextcloud/ownCloud/Seafile by default. NEXUS owns its Storage
abstraction and Cloud UI; a third-party engine is added only for a demonstrated
technical advantage and can never become the System of Record.

**DoD:** swap between two storage providers without changing drive code;
cross-tenant namespace tests green; versioning + trash restore tested.

---

## Phase 5 — NEXUS Sites

Site provisioning, Git connect, build/deploy, staging/preview/production,
environment variables (encrypted), logs, SSL, CDN, cache, rollback, backups.

**DoD:** deploy a static + a Laravel site from Git in a few clicks; failed
deploy → automatic rollback; env vars never leak via API.

---

## Phase 6 — NEXUS Billing

Products, plans, subscriptions, invoices, taxes, coupons, credits, upgrades/
downgrades/prorata, refunds, failed payments, renewals.
`PaymentProviderInterface`, Stripe sandbox first, customer portal.

**DoD:** subscribe → invoice → webhook-verified payment state; idempotent
checkout; failure/dunning path produces `PaymentFailed` events.

---

## Phase 7 — NEXUS Security

Security Center, MFA enforcement policy, session management, audit,
vulnerability/SSL/domain monitoring, backup status, Security Score with
severity/explanation/impact/remediation/action.

---

## Phase 8 — NEXUS Cloud

`CloudProviderInterface`, **one provider first** (recommended: Hetzner or DO).
VPS provisioning, lifecycle, metrics, SSH keys, firewall, snapshots, backups.
Add further providers only after one is production-solid.

---

## Phase 9 — NEXUS Deploy (CI/CD)

GitHub first (then GitLab/Bitbucket): build → test → security scan → staging →
health check → production → monitoring; automatic rollback on failure.

---

## Phase 10 — NEXUS Mail

Domain mail config, mailboxes, aliases, forwarding, quotas, spam, SPF/DKIM/
DMARC auto-config, provider abstraction. Specialized email provider, no custom
SMTP infrastructure.

---

## Phase 11 — NEXUS AI

AI Core with **permission-scoped access** (AI by permission, not by default).
AI website builder (produces real deployable code), Cloud Copilot (diagnose →
recommend → review → apply, never silent destructive action), Security
Copilot, usage tracking.

---

## Phase 12 — NEXUS Automate

Workflow engine: trigger → condition → action → result. Schedules, webhooks,
event-driven automation; sensitive actions require confirmation.

---

## Phase 13 — NEXUS Marketplace

Apps, plugins, themes, integrations, AI agents, templates. Extensible
architecture; marketplace is a distribution layer, not a rewrite.

---

## Phase 14 — Scale

Horizontal scaling, queue workers, read replicas, object storage, CDN,
multi-region, HA, disaster recovery, infrastructure orchestration.

---

## Phase 15 — Commercial launch

Security: pentest, dependency audit, secrets audit, permission audit, tenant
isolation test. Performance: load/stress, API + DB benchmarks. Reliability:
backup restore, rollback, provider/db/storage failure drills. UX: onboarding,
mobile, accessibility, error states.

---

## 4. Phase 1 decision needed

Phase 1 has two possible entry points given the environment:

**A — Backend-first (matches the spec order).** Requires installing PHP 8.3 +
Composer + PostgreSQL + Redis (or Docker Desktop) in this workspace. Full
Laravel is runnable and testable here.

**B — Contract + frontend-first.** Write the OpenAPI spec for Phase 1, scaffold
the React design system + auth UI against a typed mock, and scaffold the
Laravel app source in parallel — but defer running migrations/tests until a
backend runtime exists.

**Recommendation:** A, if the runtime can be installed — Laravel's
authorization, scopes, and RLS are the parts that must be *proven*, not just
written, and they can only be proven by running the test suite. B is the
fallback if the environment cannot gain PHP/Docker.
