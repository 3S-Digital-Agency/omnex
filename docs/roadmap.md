# OMNEX — Technical Roadmap

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
  events → audit stream). DNSSEC + propagation monitoring + real registrars
  (Namecheap, OVH, custom) are delivered.
- **Phase 4 — Storage (Drive): IMPLEMENTED** (`StorageProviderInterface`,
  S3-compatible + sandbox; upload/download, folders, versions, trash,
  quotas, tenant isolation).
- **Phase 5 — Sites: IMPLEMENTED** (`SiteProviderInterface`, sandbox + custom;
  provision/deploy/rollback, encrypted env vars, auto-rollback on failure).
- **Phase 6 — Billing: IMPLEMENTED** (`PaymentProviderInterface`, sandbox +
  Stripe; plans, subscriptions, invoices, webhook-verified activation, cancel).
- **Phase 7 — Security: IMPLEMENTED** (findings engine + Security Score,
  MFA enforcement policy, session management, SSL/certificate monitoring,
  backup status).
- **Phase 8 — Cloud: IMPLEMENTED** (VPS via `ServerProviderInterface` — sandbox/Hetzner/DO/custom —, clés SSH + coffre, métriques SSE + historique, alertes de seuil, snapshots planifiés avec rétention, validation de tokens).
- **Phase 9 — Marketing & Commercial Website: PLANNED** (site public vitrine, pages services, tarifs, preuve sociale, SEO, analytics, CTA).
- **Phase 10+ — Deploy, Mail, AI, Automate, Marketplace, Scale, Launch: PLANNED.**

---

## Phase 0 — Discovery ✅

Deliverables produced: `architecture.md`, `database.md`, `api.md`,
`security.md`, `roadmap.md`.

**Environment finding (blocker for backend runtime, see §4):** this workspace
has Node 24 / npm / pnpm but **no PHP, no Composer, no Docker, no PostgreSQL,
no Redis**. Laravel cannot be run, migrated, or tested here until one of those
is available. The frontend and the API contract can proceed regardless.

---

## Phase 1 — OMNEX Foundation

**Goal:** a real, authenticated, multi-tenant skeleton with RBAC and audit.

Scope:

- Laravel 11+ app with PostgreSQL + Redis (via Docker Compose).
- Modular structure (`app/Modules`), `Support`, port/registry shell.
- Auth: register, login, logout, email verification, MFA (TOTP), Sanctum SPA
  + API tokens, session/device management, and **passwordless passkey
  sign-in** (WebAuthn — Face ID / Touch ID / Windows Hello) with a
  `/auth/passkey/options` + `/auth/passkey/verify` contract; sandbox
  fallback keeps the demo fully functional without a platform authenticator.
- **FIDO2/WebAuthn authenticator management**: `authenticators` table
  (credential id, public key, sign count, transport, last used, full
  `CredentialRecord`), endpoints for register/verify/revoke + **adaptive
  security levels** (standard / enhanced / critical on
  `users.security_level`), and the **« My authenticators »** settings UI
  (add a YubiKey / passkey / biometric device, name it, see last-used &
  registration dates, revoke). Biometric data never leaves the device —
  OMNEX stores only public keys.
- **Full WebAuthn cryptography** (`web-auth/webauthn-lib` v5): registration
  validates the real attestation statement (packed / fido-u2f / none,
  signature over `authData ‖ clientDataHash`, origin, single-use challenge),
  stores the COSE credential public key in a serialized `CredentialRecord`,
  and authentication verifies the ES256/RS256 assertion signature with
  strictly-increasing sign-counter anti-replay. Tested end-to-end with real
  ES256 attestations (`tests/Support/WebAuthnTestKit.php`).
- **Cross-device sign-in (PC ↔ phone)**: QR-code pairing
  (`/auth/cross-device/start` + `/approve`, single-use 5-min code) — the
  phone authenticates with Face ID / Touch ID (iPhone), fingerprint / face
  unlock (Android) or a passkey, and the signed WebAuthn assertion is
  verified here; sandbox fallback keeps the demo  functional without a phone.
  UI: scannable QR dialog with an iPhone/Android platform picker on the
  login page.
- **Unknown-device detection**: a brand-new iPhone / Android / passkey must
  confirm the sign-in with a single-use 6-digit code e-mailed to the owner
  (`NewDeviceSignIn` notification, `user_devices` table, `/auth/device/verify`)
  before a session is issued; verified devices are remembered.
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

## Phase 2 — OMNEX Command Center

Dashboard with real-time overview, navigation, `Ctrl + K` command palette.
Modules: Overview, Domains, Sites, Cloud, Storage, Security, Billing, Activity.
Real-time via WebSockets; aggregated activity feed from the event bus.

**DoD:** real-time dashboard reflecting real backend events; global nav;
keyboard-first command palette.

**Members cockpit**: animated KPIs (total / pending invites / MFA-enabled /
unprotected), a **role-distribution donut** (owner/admin/developer/viewer)
with legend, an **MFA adoption progress bar** per organization and a
**members & invitations timeline** (joins and pending invites, newest first)
on the Members page.

**Activity cockpit** (Notifications & Activity page): animated KPIs (events /
events today / daily average / active hours — today's bucket merges
the live SSE stream), a **volume area chart**, a **per-type volume bar
chart** and a **7-day × 24-hour activity heatmap** (hourly intensity grid
with hover tooltips and legend).

**Period selector (7/30/90 days)** on the Security, Cloud (server metrics),
Activity and Audit cockpits: shared `PeriodSelector` component; Security and
Cloud filter persisted history samples by cutoff (Cloud metrics backfill now
spans ~90 days, one sample per day), Activity regenerates its deterministic
history and Audit filters its log table by timestamp — KPI labels stay in
sync ("Events · 7 days", "Volume on 90 days").

**Settings cockpit**: configuration gauges (MFA on/off, email verified,
interface language, linked accounts) with progress bars, a **profile
completion donut** (name, email, verified email, MFA, language, linked
account), an **integration-status donut + per-provider list** (linked /
available) and an **organization profile card** (plan, status, progress).

**Audit cockpit** (Audit Log page): animated KPIs (events / success rate /
failures / unique actors), an **action-distribution donut** (auth, members,
domains, DNS, organization…), a **per-actor frequency bar chart** and a
**success/failure result stacked bar** above the immutable log table.

---

## Phase 3 — Domain + DNS

Domain Engine + OMNEX DNS behind `DomainProviderInterface` /
`DnsProviderInterface`. Search, register, renew, transfer, contacts, privacy,
locking, nameservers; A/AAAA/CNAME/MX/TXT/NS/SRV/CAA, DNSSEC,templates, validation, import/export, history, rollback, propagation monitoring.

The Domains UI is a **cockpit**: a portfolio overview with a status
distribution donut (active / expiring soon / expired), animated KPI chips and
a per-domain **expiry timeline** (progress bars with days-left, each row
links to the domain), plus a **DNSSEC deployment progress bar** and a
**propagation progress stacked bar** (synced / pending / outdated) on the
domain detail page.

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

## Phase 4 — OMNEX Storage (Drive)

`StorageProviderInterface` with `S3Provider`, `R2Provider`, `OVHProvider`,
`MinIOProvider`. Upload/download via signed URLs, folders, sharing,
permissions, quotas, versioning, trash, search, previews, favorites, recent.

**Rule:** no Nextcloud/ownCloud/Seafile by default. OMNEX owns its Storage
abstraction and Cloud UI; a third-party engine is added only for a demonstrated
technical advantage and can never become the System of Record.

The Drive UI is a **cockpit**: an animated **quota gauge** (donut + progress
bar with used/limit), a **cumulative usage timeline** (`GET /storage/usage-history`
— daily buckets over the last 30 days, newest last, from the version history)
and a **files-by-type distribution donut** (image/media/text/document/archive/other
by MIME category).

**DoD:** swap between two storage providers without changing drive code;
cross-tenant namespace tests green; versioning + trash restore tested.

---

## Phase 5 — OMNEX Sites

Site provisioning, Git connect, build/deploy, staging/preview/production,
environment variables (encrypted), logs, SSL, CDN, cache, rollback, backups.

**DoD:** deploy a static + a Laravel site from Git in a few clicks; failed
deploy → automatic rollback; env vars never leak via API.

**Delivered:** `SiteProviderInterface` (sandbox + Custom HTTP/JSON gateway),
`SiteService` (provision/deploy/rollback/delete, encrypted env vars at rest),
`SiteController` REST API, RBAC `sites.read`/`sites.manage`, and the Sites UI
(list → provision → deploy → live, build logs, manual rollback, automatic
rollback on failed deploy). The Sites UI is a **cockpit**: a fleet overview
with a deployment-status donut (healthy / failed / rolled back /
provisioning), animated KPI chips, per-site **health bars** and a
**deployment timeline** on the detail page (vertical colored timeline with a
"Current" badge) plus a **deployments-by-status stacked bar** (live / failed /
rolled back). **Remaining:** real hosting providers (Vercel, Netlify,
Forge…), Git webhooks/auto-deploy, SSL/CDN/cache, backups.

---

## Phase 6 — OMNEX Billing

Products, plans, subscriptions, invoices, taxes, coupons, credits, upgrades/
downgrades/prorata, refunds, failed payments, renewals.
`PaymentProviderInterface`, Stripe sandbox first, customer portal.

**DoD:** subscribe → invoice → webhook-verified payment state; idempotent
checkout; failure/dunning path produces `PaymentFailed` events.

**Delivered:** `PaymentProviderInterface` (sandbox + Stripe), plans catalog,
tenant-scoped `subscriptions` + `invoices`, `BillingService` (subscribe →
webhook-verified activate/fail, idempotent redelivery, cancel), `BillingController`
REST + public webhook route, RBAC `billing.read`/`billing.manage`, audit + owner
notifications, and the Billing UI. **Coupons** (percent/amount, expiry,
redemption caps, Stripe `discounts[0][coupon]` + `omnex:stripe-sync-coupons`),
**credits** (signed ledger, applied against invoices) and **proration**
(plan change credits the unused period) and **automatic renewals**
(`omnex:billing-renewals`, scheduled daily, `--dry-run` preview, Stripe-managed
subscriptions skipped) shipped with tests. **Billing cockpit**: credit gauge
(animated donut + progress, adaptive color), cumulative spend timeline
(area chart over invoices), per-service cost breakdown (multi-segment donut
from `/billing/cost-breakdown`, aggregated by plan) — live in the Billing UI.
**Remaining:** live Stripe keys, taxes, refunds, dunning schedules, customer portal.

---

## Phase 7 — OMNEX Security

Security Center, MFA enforcement policy, session management, audit,
vulnerability/SSL/domain monitoring, backup status, Security Score with
severity/explanation/impact/remediation/action.

**Status: delivered** — the findings engine behind the Security Score is live
(`SecurityService`, `security_findings` table, `SecurityController`, RBAC
`security.read`/`security.manage`, Security Center UI, dashboard score wired to
the API). Rules: MFA off, unverified email, single-member org, expiring
domains, DNSSEC off. **MFA enforcement policy** (`mfa_policy` per organization,
optional/required, enforced via the `mfa_enforcement` finding until every
member complies), **session management** (`/sessions` — list devices with
IP/UA via stamped Sanctum tokens, revoke one or all others), **SSL/certificate
monitoring** (`SslCheckerInterface` + sandbox checker, `ssl_checks` table,
`ssl_invalid`/`ssl_expiring` findings, `/security/ssl-checks`) and **backup
status** (`backup_disabled` finding for servers without scheduled snapshots).
**Security cockpit**: persisted score samples (`security_score_samples`,
recorded on every meaningful scan/dismiss/reopen/policy change),
`/security/history` endpoint, animated score donut, severity-distribution
multi-segment donut, score-evolution area chart with scan history, and a
remediation-progress stacked bar (resolved/open/dismissed + resolution rate).

---

## Phase 8 — OMNEX Cloud ✅

Delivered: `ServerProviderInterface` with **sandbox** (deterministic) +
**Hetzner** and **DigitalOcean** (real REST APIs, configured via their tokens)
+ **Custom** (HTTP/JSON compute gateway). VPS provisioning (region/plan/image
whitelists), **start / stop / reboot / rebuild / delete**, full
`server_operations` trail, audit + RBAC (`cloud.read` / `cloud.manage`),
OMNEX Cloud UI (FR/EN) wired into the sidebar and dashboard, and a
**real-time metrics stream** (SSE `server.metrics` samples — CPU / memory /
disk — with a live dashboard card and a **persisted history**
(`server_metric_samples` + `GET /cloud/{server}/metrics/history`) powering a
live CPU sparkline; synthetic samples until each platform's time-series
metrics API is wired) and a **reusable SSH key manager**
(create/import with SHA256 fingerprints, rename/delete — **blocked while a
key is in use by servers**, with a per-key usage counter — associate a saved
key with a server at provisioning, **generate a key pair** ed25519/RSA 4096
straight from the UI — private key downloaded once, never persisted
server-side), an **encrypted private-key vault** (optional vault password
seals the private half at rest with AES-256-GCM/PBKDF2, recovered later via
`unlock` — the passphrase is never stored), and **secure key install onto
servers through the provider** (`ServerProviderInterface::installSshKey`,
only the public half is sent; sandbox/custom install for real,
Hetzner/DigitalOcean report unsupported honestly), **provider token
validation** (`omnex:cloud:verify-providers` + `GET /cloud/providers/verify` —
read-only, no-cost checks of Hetzner/DO/custom tokens before provisioning),
plus **threshold alerts**: when a sample
crosses a usage limit (CPU/memory/disk, defaults 90%, per-metric cooldown) an
OMNEX notification (`server.alert`) is sent to every member with `cloud.read`,
and **scheduled snapshots & backups** behind `ServerProviderInterface`
(`snapshot`/`listSnapshots`/`deleteSnapshot` on sandbox, Hetzner,
DigitalOcean and the custom gateway): per-server `snapshot_frequency`
(disabled/daily/weekly) + `snapshot_retention_days`, manual snapshots from the
UI, `server_snapshots` table, and a daily scheduled `omnex:server-snapshots`
command (`--dry-run` preview) that creates due snapshots and prunes expired
ones on the platform and locally.

The Cloud UI is a **cockpit**: a fleet overview (animated KPIs — total /
running / stopped / provisioning / failed — with a status-distribution
stacked bar), and per-server **metric donuts** (CPU / memory / disk, animated
with usage-aware coloring), **three shared AreaChart timelines** (one per
metric over the persisted history) and an **operations progress stacked bar**
(succeeded / pending / failed).

Remaining: per-provider real time-series metrics, firewall, provider cost
tracking. Add further providers only after one is production-solid.

---

## Phase 9 — OMNEX Marketing & Commercial Website (Public-Facing Experience)

**Goal:** turn OMNEX into not just an excellent SaaS application but also an
excellent *commercial* website capable of selling the product and the services
that come with it — marketing, acquisition and conversion, not just functional
development.

**Status: in delivery** — public homepage (hero, stats, services grid,
features, pricing cards + full comparison table, testimonials, FAQ, CTA
bands), dedicated SEO service pages (`/marketing/{service}` with unique meta,
JSON-LD Service/BreadcrumbList/FAQPage, per-service CTAs), technical SEO
(structured data, sitemap.xml, robots.txt, Open Graph/Twitter cards), and a
**contact page with lead routing** (`/contact` — public form with honeypot
anti-spam, per-IP rate limiting, optional reCAPTCHA v3, IP/UA recorded,
`contact_leads` table, `POST /v1/public/leads`, platform-owner
notifications), a **language selector** in the public header (EN/FR,
instant switch, persisted preference, `hreflang` + `<html lang>` synced), and
**privacy-conscious analytics + conversion tracking** (`lib/analytics.ts` —
local event store with UTM capture/attribution, optional GA4 via
`VITE_GA4_MEASUREMENT_ID` gated behind explicit consent, `pageview`,
`cta_clicked`, `signup_started`, `lead_submitted`, `quote_requested`,
`demo_requested`).

**Landing page engine (CMS):** campaign pages served on `/landing/:slug` with
per-locale JSON sections (`hero`, `offer`, `promo`, `comparison`, `features`,
`cta`, `faq`), rendered by `LandingPageView` with the design system + unique
SEO meta, `hreflang` and `Product`/`BreadcrumbList` JSON-LD. Backed by a
Laravel CMS (`landing_pages` table, public show gated to published, owner-only
management API) and managed from the app at `/campaigns` (list, editor,
publish/unpublish, delete). Remaining: blog/content hub, remarketing pixels +
A/B testing harness.

**Placement:** Phase 9 deliberately sits right after the core product is solid
(Phase 8) and before the deep platform phases (Deploy, Mail, AI…), because the
commercial site should drive early signups in parallel with product growth. It
is a separate deliverable from the authenticated app: a **public, marketing-
optimized site** (own routing, own pages, no login required) sharing the OMNEX
design system and brand.

### Objectives

- Present the company, its value proposition and advantages at a glance.
- Present each service (Domains, Sites, Cloud, Drive/Storage, Billing, Security,
  Mail, AI, Automate…) on dedicated pages for SEO and conversion.
- Structure every page to **sell**: generate leads and turn visitors into
  customers (trial signup, paid plans, contact, quote requests).
- Build trust with social proof: testimonials, customer reviews, case studies.
- Compete visually with market leaders: premium, coherent brand identity.
- Make the site easy to extend: new campaigns, services, promotions and
  landing pages without re-architecting.

### Scope

**1. Marketing site (public)**
- High-end **homepage**: hero + value proposition, advantages, product
  highlights, live screenshots/animations, primary/secondary CTAs, social proof
  strip, pricing teaser, final CTA band.
- **Services overview** page + **dedicated page per service** (Domains, DNS,
  Sites, Cloud, Drive, Security, Billing, plus future Mail/AI/Automate) — each
  with benefits, use cases, features, pricing anchor and its own CTA.
- **Pricing / plans** section: free/paid plans, per-service pricing, feature
  comparison table, FAQ anchors; billing ties into the Phase 6 plans catalog.
- **About / company** page: story, mission, team, commitment (sovereign cloud,
  open standards, « Serveurs du Peuple » values).
- **Contact & lead capture**: contact form, quote/estimate request form
  (« Demander une soumission »), lead routing + notifications.
- **Legal pages**: Terms, Privacy, GDPR, SLA.
- **Blog / content hub**: posts, guides, comparisons, release notes.
- **Testimonials & case studies**: review system, logos, quantified outcomes,
  video/quote formats.
- **FAQ**: per-product FAQ sections optimized for objections and featured
  snippets (FAQPage structured data).

**2. SEO strategy**
- Technical SEO: clean URL structure, sitemap.xml, robots.txt, canonical tags,
  Open Graph/Twitter cards, meta descriptions, headings hierarchy, internal
  linking, Core Web Vitals (LCP/CLS/INP) budgets.
- Structured data: Organization, Product/Service, FAQPage, BreadcrumbList,
  Review/AggregateRating, LocalBusiness if applicable.
- Keyword strategy: service terms, competitor comparison terms
  (« vs Hetzner », « vs Netlify »…), long-tail questions; content calendar.
- **Landing pages**: campaign-specific pages (offer, promo, feature launch,
  comparison), each with tracking + dedicated CTA.

**3. Conversion optimization (CRO)**
- Strategic CTAs everywhere: Demander une soumission, Acheter, Nous contacter,
  Commencer (essai gratuit), Réserver une démo, etc.
- Conversion paths: visitor → trial signup → onboarding → paid plan;
  form abandonment recovery; exit-intent offer where relevant.
- A/B testing harness for hero, CTAs, pricing pages, landing pages.
- UX: mobile-first, fast loads, clear hierarchy, minimal friction, honest
  messaging (no dark patterns).

**4. Analytics, tracking & remarketing**
- Privacy-conscious analytics (self-hosted Matomo/Plausible class) + consent
  management; optional GA4 via adapter.
- Conversion tracking (signup, trial start, paid, quote request) with event
  taxonomy aligned to the product's billing events.
- Remarketing/retargeting pixel adapters (opt-in), UTM tracking end-to-end
  (campaign → signup attribution stored on the organization).

**5. Architecture & brand**
- CMS/content layer with a **page/data model** (hero, sections, FAQ items,
  testimonials, pricing tiers) so new pages/campaigns are content additions,
  not code rewrites.
- i18n EN/FR reusing the app's i18n system; locale-aware SEO (hreflang).
- Brand: premium visual identity consistent with the OMNEX logo, typography,
  color palette, spacing system; reusable marketing component kit.

### Priorities (P0 → P2)

- **P0 — Homepage + Pricing + CTA/conversion paths** (the selling core).
- **P0 — Analytics + conversion tracking** (measure from day one).
- **P1 — Per-service pages + SEO foundations** (sitemap, structured data, meta).
- **P1 — Contact/quote forms + lead handling.**
- **P1 — Testimonials + case studies + FAQ.**
- **P2 — Blog/content hub. Landing page engine delivered.**
- **P2 — Remarketing + A/B testing harness.**

### Definition of Done

- A visitor can go from homepage → service page → pricing → **sign up for a
  free trial** without leaving the marketing site, and the signup is tracked
  end-to-end (UTM → organization attribution).
- Every service has a dedicated public page with unique meta, structured data
  and its own CTA.
- Pricing page matches the Phase 6 plans catalog (single source of truth).
- A quote request submitted from the site reaches the team (notification +
  lead record) within one minute.
- Lighthouse/performance budget green on homepage and service pages
  (Core Web Vitals targets met); sitemap + robots + hreflang valid.
- All public pages pass the same security scan (no auth bypass, no data leak,
  CSP in place); the marketing site shares the brand kit but stays fully
  separated from the authenticated app's routing and data.

---

## Phase 10 — OMNEX Deploy (CI/CD)

GitHub first (then GitLab/Bitbucket): build → test → security scan → staging →
health check → production → monitoring; automatic rollback on failure.

---

## Phase 11 — OMNEX Mail

Domain mail config, mailboxes, aliases, forwarding, quotas, spam, SPF/DKIM/
DMARC auto-config, provider abstraction. Specialized email provider, no custom
SMTP infrastructure.

---

## Phase 12 — OMNEX AI

AI Core with **permission-scoped access** (AI by permission, not by default).
AI website builder (produces real deployable code), Cloud Copilot (diagnose →
recommend → review → apply, never silent destructive action), Security
Copilot, usage tracking.

---

## Phase 13 — OMNEX Automate

Workflow engine: trigger → condition → action → result. Schedules, webhooks,
event-driven automation; sensitive actions require confirmation.

---

## Phase 14 — OMNEX Marketplace

Apps, plugins, themes, integrations, AI agents, templates. Extensible
architecture; marketplace is a distribution layer, not a rewrite.

---

## Phase 15 — Scale

Horizontal scaling, queue workers, read replicas, object storage, CDN,
multi-region, HA, disaster recovery, infrastructure orchestration.

---

## Phase 16 — Commercial launch

Go-to-market on top of the **Phase 9 marketing & commercial website**: launch
campaigns (offers, landing pages, remarketing), first paid customers, case
studies from early adopters. Security: pentest, dependency audit, secrets
audit, permission audit, tenant isolation test. Performance: load/stress, API
+ DB benchmarks (incl. marketing-site Core Web Vitals). Reliability: backup
restore, rollback, provider/db/storage failure drills. UX: onboarding, mobile,
accessibility, error states — on both the app and the public site.

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
