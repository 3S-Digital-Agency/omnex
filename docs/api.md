# OMNEX — API Map

> Phase 0 deliverable. API-first is a hard rule: every module exposes a clean,
> versioned REST API, and the React app is generated against the OpenAPI spec.

---

## 1. Conventions

- Base path: `/api/v1`. Version is part of the path; breaking changes bump it.
- Content type: `application/json` in/out. Binary (upload/download) uses
  signed URLs + multipart where needed.
- Authentication:
  - SPA: httpOnly cookie session (Sanctum SPA auth).
  - API consumers: bearer token with **scoped abilities** (Sanctum tokens).
- Every request resolves an **active organization** (tenant context) and is
  authorized against it. A missing/invalid tenant context → `403`, never a
  fallback.
- **Errors: RFC 7807** (`application/problem+json`):

```json
{
  "type": "https://omnex.dev/errors/validation",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "errors": { "name": ["The name field is required."] }
}
```

- **Pagination**: `?page=1&per_page=25` returning

```json
{ "data": [], "meta": { "current_page": 1, "per_page": 25, "total": 0 } }
```

- **Mutating endpoints that cost money or change external state** accept an
  `Idempotency-Key` header and are retried safely.
- Rate limiting: per-user and per-IP, with stricter limits on auth and billing
  endpoints. Public DNS/domain search is cached + rate-limited hard.

---

## 2. Resource naming

- Plural nouns, hierarchical where ownership matters:
  `/organizations/{org}/domains/{domain}/dns/records`.
- Sub-actions that aren't a clean verb use a suffix:
  `POST /organizations/{org}/domains/{domain}/renew`.
- List → `GET`, show → `GET /{id}`, create → `POST`, replace → `PUT`,
  partial → `PATCH`, delete → `DELETE`.

---

## 3. Endpoint map (by module, phased)

```
/api/v1/auth              login, logout, register, mfa/challenge, mfa/verify,
                          webauthn/*, oauth/{provider}/redirect, oauth/{provider}/callback,
                          password/forgot, password/reset
/api/v1/me                profile, sessions, trusted-devices, api-tokens, notifications

/api/v1/organizations     CRUD, members, invitations, roles, teams, switch-active
/api/v1/users             CRUD (scoped to org), roles/assign, deactivate

/api/v1/domains           search, register, renew, transfer, contacts, privacy,
                          locking, nameservers, auto-renew, expiration
/api/v1/dns               zones, records (A/AAAA/CNAME/MX/TXT/NS/SRV/CAA),
                          templates, import, export, history, rollback,
                          propagation, dnssec
/api/v1/ssl               certificates, issue, renew, status

/api/v1/sites             CRUD, environments, env-vars, deployments (trigger,
                          status, logs, rollback), git/connect, domains/attach
/api/v1/deployments       list, stream logs (WS), health-check, rollback

/api/v1/cloud             servers, ssh-keys, firewall, snapshots, backups,
                          metrics, regions/sizes, power actions
/api/v1/storage           drive: folders, files, upload (signed), download,
                          shares, versions, trash, search, favorites, recent
/api/v1/email             domains, mailboxes, aliases, forwarding, quotas,
                          spam, dns-autoconfig (SPF/DKIM/DMARC)

/api/v1/billing           plans, subscriptions, invoices, payment-methods,
                          checkout, portal, coupons, credits, webhooks/{provider}
/api/v1/security          score, findings, scan/trigger, fix/apply, mfa-policy
/api/v1/backups           plans, snapshots, restore, restore-test, retention
/api/v1/automation        workflows, runs, triggers, conditions, actions, webhooks
/api/v1/observe           metrics, logs, traces, uptime, alerts
/api/v1/ai                completions, site-builder, copilot/ask, diagnostics,
                          usage, permissions
/api/v1/audit             logs, export
/api/v1/organizations/{org}/activity   Command Center feed (aggregated events)
```

---

## 4. OpenAPI strategy

- **Single source of truth**: one `openapi.yaml` at the repo root (or
  generated from Laravel route + form-request metadata).
- The frontend API client and the backend validation types are generated from
  this spec. Hand-written API code that drifts from the spec is a CI failure.
- Spec is versioned alongside code; `/openapi.yaml` is published at build time.
- Phase 1 ships the spec for `auth`, `me`, `organizations`, `users`, `audit`,
  `notifications` before the UI consumes them.

---

## 5. Non-negotiable API rules

1. No endpoint exists without authorization (default-deny middleware; each
   route must opt in to a policy or an ability).
2. Every response is scoped to the active organization. Cross-tenant IDs are
   rejected as `404` (never `403`, to avoid existence leaks).
3. Secrets are never returned (`environment_vars.value`, provider credentials).
4. Write endpoints are audited (`audit.md` payload in `security.md`).
5. Long operations (deploy, domain transfer, VPS create) return `202` with a
   status resource; progress streams over WebSocket, never via polling in a
   tight loop.
