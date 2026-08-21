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
| 7 | Security — findings engine, MFA policy, sessions, SSL/vuln monitoring | ✅ Delivered |
| 8 | OMNEX Cloud — VPS engine (sandbox · Hetzner · DigitalOcean · Custom) | ✅ Delivered |
| 9 | Marketing & Commercial Website — homepage, services, tarifs, SEO, contact/leads, analytics, blog, A/B | ✅ Delivered |
| 10 | OMNEX Deploy — CI/CD pipeline (build → test → scan → staging → health → prod → rollback) | ✅ Delivered |
| 11+ | Mail, AI, Automate, Marketplace, Scale, Launch | 🔜 Planned |

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
- **Passwordless & strong authentication** — full **WebAuthn / FIDO2**
  (via `web-auth/webauthn-lib`): passkeys, YubiKey, Windows Hello, Touch ID /
  Face ID, plus TOTP and recovery codes. Cryptographic verification is done
  server-side (attestation at registration, signature + anti-replay at login).
  **Cross-device login** (iPhone/Android): scan a QR code with the phone to
  authenticate through its platform passkeys. **Unknown-device detection**: a
  fresh device is flagged, the user is notified by e-mail and must pass a
  verification step before it is trusted and listed under *My authenticators*.
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
- **Cloudflare DNS** provider behind `DnsProviderInterface` (zone listing,
  record CRUD, DNSSEC toggle) — activates once `CLOUDFLARE_API_TOKEN` is set.
- **SSL engine** behind `SslProviderInterface` — issue / renew / revoke / status:
  a deterministic **sandbox**, **Cloudflare Universal SSL** (real API) and
  **Let's Encrypt** (RFC 8555 ACME client, zero-dependency, DNS-01 challenge
  placed via the organization's active DNS provider). Certificates are
  persisted (`ssl_certificates`, fullchain PEM) and issued automatically on
  domain registration/transfer, renewed on renew. Selectable per organization
  like every other provider.

### 💾 OMNEX Drive (Phase 4)
- `StorageProviderInterface` with sandbox + S3-compatible providers
  (AWS SigV4 over Guzzle — **S3**, **Cloudflare R2**, MinIO / OVH Object
  Storage), each explicit and selectable per organization.
- Folders, files, versioning, trash/restore, per-organization quota,
  signed download/upload URLs, audit trail, RBAC (`storage.read` /
  `storage.manage`).
- Rule: **no Nextcloud/ownCloud/Seafile by default** — OMNEX owns its storage
  abstraction and its own cloud UI.

### 🧱 OMNEX Sites (Phase 5)
- `SiteProviderInterface` with **sandbox** (deterministic), **Custom**
  (HTTP/JSON hosting gateway) and **Cloudflare Pages** (deploy, preview
  deployments, rollback via the real API) providers, selectable per request.
- Provision sites from Git (static / Laravel / Next), deploy builds, list
  deployments and build logs, roll back to any previous live release.
- **Failed deploy → automatic rollback** to the previous live deployment.
- Environment variables are encrypted at rest and **never returned by the
  API** — only their key names are exposed (`sites.read` / `sites.manage`).

### 🖧 OMNEX Cloud (Phase 8)
- `ServerProviderInterface` with **sandbox** (deterministic) + **Hetzner** and
  **DigitalOcean** (real REST APIs, activate once their tokens are set) +
  **Custom** (HTTP/JSON compute gateway) providers, selectable per request.
- Provision VPS servers (region / plan / image whitelists), manage power state
  (**start / stop / reboot**), **rebuild** onto a new image, delete.
- **Real-time metrics** (`GET /cloud/{server}/metrics/stream`, SSE): live CPU /
  memory / disk samples streamed every `interval` seconds (deterministic
  synthetic samples until each platform's time-series metrics API is wired).
  Each sample is **persisted** (`server_metric_samples`) and served by
  `GET /cloud/{server}/metrics/history` — the dashboard card draws a live
  CPU sparkline from the last 60 samples.
- **Threshold alerts**: when a sample crosses a usage limit (CPU / memory /
  disk, defaults 90%, per-metric cooldown) an OMNEX notification
  (`server.alert`, severity `warning`, clickable to `/cloud`) is sent to every
  member with `cloud.read` — throttled so a sustained overload does not spam
  the feed (`omnex.cloud.alerts.*` config).
- **Scheduled snapshots & backups**: every provider exposes
  `snapshot` / `listSnapshots` / `deleteSnapshot` (Hetzner images,
  DigitalOcean snapshots, custom gateway). Manual snapshots from the UI, plus
  an automatic schedule per server (`snapshot_frequency` disabled/daily/weekly)
  with a **retention policy** (`snapshot_retention_days`) enforced by the
  scheduled `omnex:server-snapshots` command (`--dry-run` preview, registered
  in the Laravel scheduler): expired snapshots are pruned on the platform and
  in `server_snapshots`.
- **Provider token validation**: `omnex:cloud:verify-providers` (artisan) or
  `GET /cloud/providers/verify` — every provider proves its configured token
  with a **read-only, free** API call (Hetzner `GET /servers`, DigitalOcean
  `GET /account`, custom gateway `ping`), so real tokens are validated before
  anything is provisioned or billed. The create-server dialog only lists
  **configured** providers (tokens set in `backend/.env`).
- **Reusable SSH keys** (`/cloud/ssh-keys`): create/import OpenSSH keys with
  computed SHA256 fingerprints (duplicates rejected per tenant), rename/delete,
  and associate a saved key with a server at provisioning (`ssh_key_id` — the
  key body is snapshotted, so deleting a key never breaks an existing server).
  **Generate a key pair** directly from the UI (ed25519 or RSA 4096, real
  `ssh-keygen` with a PHP/OpenSSL fallback): the public half is registered,
  the private key is downloaded exactly once and **never persisted or
transmitted again** server-side. Keys show a **usage counter** ("used by N
  servers") and **cannot be deleted while in use** — the API rejects the
  deletion with a 422 until the key is removed from its servers.
- **Encrypted private-key vault**: generate a pair with an optional **vault
  password** and the private half is sealed at rest (AES-256-GCM, PBKDF2 key
  derivation — the passphrase itself is never stored, only a verifier), then
  recovered later via `/cloud/ssh-keys/{key}/unlock` with that password; a
  wrong password is rejected before any decryption, and the plaintext is
  never logged. **Secure copy to servers**: a saved key can be installed on
  an existing server through its provider (`POST /cloud/{server}/ssh-key`,
  `ServerProviderInterface::installSshKey`) — only the **public** half is
  sent; sandbox + custom install for real, Hetzner/DigitalOcean report
  `unsupported` honestly (their platforms apply keys at provisioning/rebuild).
- Every power action leaves a `server_operations` trail (type, status,
  timestamps, error) — OMNEX is the system of record (`cloud.read` /
  `cloud.manage`, audit on every mutation).

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
  `credit_applied` / `amount_due`. A coupon **admin page** (`/billing/coupons`)
  creates/activates/deactivates coupons and shows per-organization usage.
- **Automatic renewals**: `omnex:billing-renewals` (scheduled daily) rolls
  overdue sandbox subscriptions into their next period and records the renewal
  invoice (coupon + credits applied); Stripe-managed subscriptions renew via
  Stripe webhooks and are skipped. Run with `--dry-run` to preview.

### 🛡️ Security Center (Phase 7)
- Findings engine behind the live score: MFA, unverified email, single-member
  organization, expiring domains, DNSSEC off — each with severity, impact and
  remediation, dismissible/reopenable (`security.read` / `security.manage`).
- **MFA policy** (enforce per role / optional-required toggle), **session
  management** (list / revoke own + all sessions) and **SSL / vulnerability
  monitoring** surfaced in the security cockpit.
- Score recomputed on demand (`scan`); dashboard score and Security Center are
  both driven by the same API.

### 🌍 Marketing & Commercial Website (Phase 9)
- Public-facing site (`/`) separate from the authenticated app: **homepage**
  (hero, value proposition, CTAs, social proof), **service pages** with
  unique meta (domains, cloud, sites, storage, security, billing), **pricing
  with plan comparison**, **contact form** with lead routing (rate-limited,
  anti-spam, IP/UA recording), FAQ, footer.
- **Technical SEO** — meta/OG tags, JSON-LD (Organization, FAQPage, Article),
  sitemap, EN/FR **hreflang**, per-page meta hook, language selector in the
  public header, browser-language detection.
- **Blog / content hub** (bilingual posts + guides, Article JSON-LD),
  **landing-page engine** (CMS-configured campaign pages: offer, promo,
  comparison) and an **A/B testing harness** (hero/CTA/pricing variants with
  conversion tracking).
- **Analytics & consent** — pageview / CTA / conversion tracking with UTM
  capture and a cookie-consent banner (opt-in/opt-out) driving `setConsent`.

### 🚀 OMNEX Deploy (Phase 10)
- **Public health endpoint** `GET /api/v1/health` — liveness/readiness
  (service, version, environment, DB status; 503 `degraded` when the DB is
  unreachable). No auth, no internals leaked — used by CI, staging/prod
  checks and load balancers.
- **CI hardened** (`.github/workflows/ci.yml`): DCO, Pest against PostgreSQL
  16 (with real migrations), typecheck + vitest + **production build**, and a
  dedicated **security job** (`composer audit` + `pnpm audit`).
- **Production images** — `backend/Dockerfile` (PHP 8.3 FPM, optimized,
  non-root) and `frontend/Dockerfile` (pnpm build → nginx SPA with history
  fallback + caching).
- **Deploy pipeline** (`.github/workflows/deploy.yml`): build & push images →
  deploy **staging** → health check → promote **production** → health check →
  **automatic rollback** to the previous release on any failure.
- **Monitoring** (`.github/workflows/monitoring.yml`): probes production
  every 15 min, opens an `incident` issue on failure and closes it on
  recovery.

### ⚙️ Architecture: providers, features & provisioning

See [`docs/architecture-providers.md`](docs/architecture-providers.md) for
`ResolvesTenantProvider`, the SSL engine and the feature-flag system detailed
above — with a worked multi-tenant provisioning use case.

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

Demo account: `demo@omnex.cloud` / `password`. In mock mode, in-memory state
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

Demo accounts (seeded): `demo@omnex.cloud` and `dev@omnex.cloud`, both `password`.

Scheduled tasks (domain-expiration warnings, billing renewals) run through the
Laravel scheduler — in dev start `php artisan schedule:work`; in production add
`* * * * * cd backend && php artisan schedule:run` to cron. Renewals can be
previewed with `php artisan omnex:billing-renewals --dry-run`.

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
| `DnsProviderInterface` | sandbox · Cloudflare |
| `StorageProviderInterface` | sandbox · S3-compatible (R2 / MinIO / OVH) |
| `SslProviderInterface` | sandbox · Cloudflare Universal SSL · Let's Encrypt (ACME) |
| `SiteProviderInterface` | sandbox · Cloudflare Pages · Custom |
| `SocialAuthProviderInterface` | sandbox · Google · Microsoft · Apple · Facebook · Amazon · GitHub · OpenAI · Serveurs du Peuple |
| `DnsPropagationCheckerInterface` | sandbox (deterministic) |
| `ServerProviderInterface` | sandbox · Hetzner · DigitalOcean · Custom |

### Dynamic provider switching, feature flags & org provisioning

- **Strategy/Factory everywhere** — every technical brick (storage, sites,
  cloud, domains, DNS, SSL) resolves its active provider through a shared
  `ResolvesTenantProvider` trait: per-organization override
  (`organizations.settings.<service>_provider`) with environment fallback.
  Providers are **data, not code** — swap one without touching module code.
- **Feature flags / perks** (`config/omnex.features`, 16 typed flags) — resolved
  as org override → plan tier → default; enforced server-side by a `feature:`
  middleware (403) and surfaced in the UI (sidebar gating, `useFeatures()`).
- **Real provisioning** — `OrganizationService::create()` configures a new
  organization atomically: sandbox providers assigned + default perks per plan,
  so a fresh tenant is immediately functional and isolated (`TenantScope`).

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
| Frontend tests (`pnpm test`) | ✅ 95/95 |
| Frontend build (`pnpm build`) | ✅ green |
| Backend (`php artisan test`) | ✅ 385 passed + 1 skipped (1543 assertions) |
| CI (GitHub Actions — Pest + typecheck + vitest + audits) | ✅ configured (`.github/workflows/ci.yml`) |
| Deploy pipeline (staging → prod → rollback) | ✅ configured (`.github/workflows/deploy.yml`) |
| Monitoring (health cron + incident issues) | ✅ configured (`.github/workflows/monitoring.yml`) |
| Dependabot (Composer + pnpm, grouped) | ✅ configured (`.github/dependabot.yml`) |

The Laravel backend is validated against a local portable PHP + PostgreSQL
setup (see `infra/dev-env.sh`); `php artisan test` runs the full Pest suite.

---

## License

OMNEX uses a **multi-license matrix** (see [`docs/licensing.md`](docs/licensing.md)):

| Component | License |
|---|---|
| OMNEX Core (this repository) | 🟢 Apache License 2.0 |
| Protocols / interfaces / OpenAPI | 🟢 Apache 2.0 |
| SDKs & libraries | 🟢 MIT or Apache 2.0 |
| Documentation | 🟢 CC BY-SA 4.0 |
| OMNEX™ brand | 🔐 Distinct brand policy |
| Operated infrastructure / enterprise services | ⏳ To be defined (must allow commerce) |

The full Apache 2.0 license text lives in [`LICENSE`](LICENSE). Governance is
defined by the public constitution in [`GOVERNANCE.md`](GOVERNANCE.md)
(roles, decision-making, maintainers, DCO/CLA). The OMNEX™ brand is governed
by the distinct [`BRAND_POLICY.md`](BRAND_POLICY.md). Contributions are
governed by DCO or CLA (strategy to be decided); see the licensing policy
for details. Want to help? Read [`CONTRIBUTING.md`](CONTRIBUTING.md)
(contribution workflow, commit conventions, DCO sign-off).
