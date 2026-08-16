# NEXUS — Security Model

> Phase 0 deliverable. Security and tenant isolation are structural invariants,
> not features. Anything in here that is violated is a STOP condition.

---

## 1. Identity & Access (IAM)

```
User
 └─ Membership ── Organization (tenant)
      │
      ├─ Team (optional)
      └─ Role ── RolePermission ── Permission ── Resource (scope)
```

- A user authenticates once; authorization is always evaluated against the
  **active organization** on the request, never against a global user role.
- Permissions are keyed like `domains.register`, `dns.records.write`,
  `billing.invoices.read`, and may be scoped to a resource type + id (e.g. a
  single site). Absence of a grant = deny.
- Built-in roles (owner, admin, developer, billing, viewer) are seed data with
  a fixed permission set; custom roles are per-organization.
- **Least privilege by default**: new API tokens get explicit abilities; new
  members start with the lowest role and are elevated explicitly.

### Authentication methods

| Method | Phase | Notes |
|---|---|---|
| email/password | 1 | Argon2id, verified email required for tenant actions |
| MFA (TOTP) | 1 | required for owner role; policy-configurable |
| WebAuthn / passkeys | 2+ | phishing-resistant; preferred over SMS |
| OAuth / SSO (OIDC) | later | org-level SSO config |
| Session management | 1 | device list, revoke, trust, idle timeout |
| API tokens | 1 | scoped abilities, expiry, rotation |
| Service accounts | later | non-human principals, no password |

---

## 2. Tenant isolation (the core guarantee)

Four independent layers:

1. **Global scope** — every tenant model auto-filters by `organization_id`.
2. **PostgreSQL RLS** — DB-enforced `organization_id = current_setting(...)`;
   the app sets the tenant per connection. A bug in one layer is caught by the
   other.
3. **Storage namespace** — object keys are prefixed `org/{org_id}/`; signed
   URLs encode the tenant. No tenant can reference another's prefix.
4. **Cache/queue isolation** — cache keys and queue job payloads include the
   tenant id; listeners re-resolve and re-authorize before acting.

**Cross-tenant attack tests (required, Phase 1+):**

- User A cannot read/modify/delete User B's org, domain, DNS, file, invoice.
- Crafting a URL/ID from another tenant returns `404`, never data.
- A tenant-scoped API token cannot be replayed against another org.
- An expired/deleted membership cannot continue acting via cached session.
- Storage signed URLs for org A are rejected against org B's bucket context.
- RLS holds even if the global scope is bypassed by mistake (defense test).

---

## 3. Secrets & credentials

- No secrets in Git, env files committed, or logs. `.env` is git-ignored;
  `.env.example` documents keys with no values.
- Provider credentials (registrar, DNS, storage, payment) are stored
  **encrypted at rest** (app-managed key, outside the DB), per-organization.
- Payment webhooks are verified by signature before any state change.
- Third-party API keys are scoped to the minimum the provider allows; the
  database stores only what is needed to operate (last4, reference, fingerprint).
- Secret rotation is a documented, scripted procedure.

---

## 4. Audit

Every critical action is written to `audit_logs` **before** the effect is
committed where possible:

```
user, timestamp, ip, device, action, resource_type, resource_id,
before, after, result, organization_id
```

- Immutable append-only semantics (no update/delete API on audit rows).
- Covers: auth events, membership/role changes, domain/DNS mutations, billing
  events, provider operations, destructive actions, automation runs.
- The React Command Center exposes a filterable audit view (Phase 1).

---

## 5. Security Score (Phase 7)

The score aggregates weighted findings across account, domains, DNS, SSL, sites,
servers, backups, permissions. Every finding carries:

```
severity, explanation, impact, remediation, action
```

- Findings are computed from real state (expiring certs, missing MFA on owner,
  unbacked-up server), never decorative.
- Remediation actions are user-confirmed; nothing is fixed silently.

---

## 6. Threat model summary (initial)

| Threat | Control |
|---|---|
| Cross-tenant data access | global scope + RLS + namespace + authz tests |
| Privilege escalation | RBAC least-privilege, resource scoping |
| Credential stuffing / brute force | rate limiting, Argon2id, MFA |
| Phishing / session theft | WebAuthn, device management, short idle timeout |
| Supply chain | pinned deps, `npm audit`/`composer audit` in CI |
| SSRF via provider adapters | allow-listed hosts, no user-controlled URLs to internals |
| Idempotency/dup payments | `Idempotency-Key` + provider idempotency |
| Destructive action | confirmation flow + audit + rollback where possible |

---

## 7. STOP conditions (from the master spec)

Cross-tenant data access, exposed secrets, credentials in Git, unsecured
payments, critical endpoint without authorization, unrestorable backups,
destructive action without confirmation, strongly-coupled architecture,
simulated real data, untested destructive migration — **any of these halts work
until fixed.**
