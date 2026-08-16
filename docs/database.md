# NEXUS — Database Map

> Phase 0 deliverable. PostgreSQL is the system of record for everything except
> file objects (S3-compatible storage). Redis is cache + queue, never a source
> of truth.

---

## 1. Tenancy strategy

**Decision: single database, logical tenant isolation, with defense in depth.**

- Every tenant-scoped table carries a required `organization_id` foreign key
  (`tenants` = `organizations`).
- A **global scope** automatically appends `WHERE organization_id = ?` to every
  query through the model, using the request's active-organization context.
  Bypassing the scope is an explicit, audited act (`withoutTenancy()`), only
  allowed in jobs that legitimately span tenants (e.g. cron billing).
- **PostgreSQL Row-Level Security** is enabled as a second layer so that even a
  bug in the application layer cannot cross tenant boundaries.
- Storage objects are partitioned by tenant prefix: `org/{uuid}/...` so an
  object key is unforgeable across tenants. Signed URLs are scoped per tenant.

Rejected alternatives (with reasons, for the record):

- **Per-tenant database/schema** — strong isolation but heavy to operate at
  scale (migrations, connection pooling, backups per tenant). Revisit only if a
  high-isolation enterprise tier demands it.
- **One DB, no RLS** — isolation depends entirely on every query being correct.
  Too fragile for a platform whose whole promise is trust.

---

## 2. Naming & typing conventions

- Tables: `snake_case` plural (`organizations`, `dns_records`).
- Primary keys: `uuid` (ULID-friendly) for tenant-facing entities; internal
  high-volume tables (audit, events) may use `bigint identity` where UUID
  ordering hurts index locality.
- Foreign keys: `{entity}_id`, always indexed.
- Timestamps: `timestamptz` everywhere (UTC, never naive).
- Money: integer cents + `currency` (3-letter code). Never floats.
- Soft deletes only where a user-facing "trash/restore" exists (Drive files).
  Otherwise hard deletes + audit trail.
- Every table: `created_at`, `updated_at`. Critical tables add `created_by`.

---

## 3. Entity map by module (Phase 1 → 15)

### IAM / Organizations (Phase 1)

```
users              (id, name, email, password_hash, email_verified_at,
                    mfa_enabled, locale, status, last_login_at)
organizations      (id, name, slug, plan_tier, status, settings jsonb)
memberships        (id, organization_id →, user_id →, role_id →,
                    status, invited_by, joined_at)
teams              (id, organization_id →, name)
team_members       (id, team_id →, membership_id →)
roles              (id, organization_id → nullable=global, name, key)
permissions        (id, key, description)          -- static seed
role_permissions   (id, role_id →, permission_id →, resource_type,
                    resource_id nullable)
invitations        (id, organization_id →, email, role_id →, token,
                    expires_at, accepted_at)
sessions           (id, user_id →, token_id, ip, user_agent, device,
                    last_seen_at, revoked_at)
api_tokens         (id, user_id →, organization_id →, name, abilities,
                    last_used_at, expires_at, revoked_at)
audit_logs         (id, organization_id →, user_id →, action, resource_type,
                    resource_id, before jsonb, after jsonb, ip, user_agent,
                    result, created_at)
notifications      (id, organization_id →, user_id →, type, title, body,
                    data jsonb, read_at)
```

### Domains / DNS (Phase 3)

```
domains            (id, organization_id →, name, registrar, status,
                    registered_at, expires_at, auto_renew, locked, privacy,
                    transfer_lock, contacts jsonb)
domain_events      (id, domain_id →, type, provider_ref, metadata jsonb)
dns_zones          (id, domain_id →, provider, status, soa jsonb, dnssec jsonb)
dns_records        (id, zone_id →, type, name, value, ttl, priority, flags,
                    status)
dns_history        (id, zone_id →, change jsonb, actor, reverted_from)
ssl_certificates   (id, domain_id →, provider, issuer, expires_at, status,
                    san jsonb, renewal jsonb)
```

### Sites (Phase 5)

```
sites              (id, organization_id →, name, type, git_provider,
                    repo, branch, root_dir, build_cmd, deploy_config jsonb)
site_environments  (id, site_id →, name[staging|production], url, status)
environment_vars   (id, environment_id →, key, value_encrypted, scope)
deployments        (id, site_id →, environment_id →, commit, status, logs,
                    started_at, finished_at, rollback_from)
```

### Storage / Drive (Phase 4)

```
drives             (id, organization_id →, provider_id →, bucket, quota_bytes)
drive_entries      (id, drive_id →, parent_id →, name, kind[file|folder],
                    mime, size, storage_key, version, checksum, trashed_at)
drive_versions     (id, entry_id →, storage_key, size, checksum, created_by)
drive_shares       (id, entry_id →, shared_with_type, shared_with_id,
                    permission, expires_at)
```

### Billing (Phase 6)

```
products           (id, key, name, type[plan|addon], active)
prices             (id, product_id →, currency, unit_amount, interval)
subscriptions      (id, organization_id →, price_id →, provider_ref, status,
                    current_period_start, current_period_end, cancel_at)
invoices           (id, organization_id →, provider_ref, number, subtotal,
                    tax, total, currency, status, paid_at, pdf_url)
payment_methods    (id, organization_id →, provider_ref, type, last4, default)
coupons            (id, code, type, value, valid_until, max_uses)
credits            (id, organization_id →, amount, currency, reason, expires_at)
transactions       (id, organization_id →, invoice_id →, provider_ref,
                    amount, currency, status, kind)
```

### Cloud (Phase 8) / Deploy (Phase 9) / Automate (Phase 12)

```
servers            (id, organization_id →, provider_id →, provider_ref, name,
                    region, size, ipv4, ipv6, status, ssh_key_id →)
ssh_keys           (id, organization_id →, name, public_key, fingerprint)
firewall_rules     (id, server_id →, protocol, port, cidr, action)
snapshots          (id, server_id →, provider_ref, size, created_at, type)
workflows          (id, organization_id →, name, enabled, trigger jsonb)
workflow_runs      (id, workflow_id →, status, input jsonb, output jsonb,
                    error, started_at, finished_at)
```

### Security / Observe (Phase 7, 20)

```
security_findings  (id, organization_id →, category, severity, status,
                    title, explanation, impact, remediation, resource jsonb,
                    detected_at, resolved_at)
security_scores    (id, organization_id →, score, breakdown jsonb, computed_at)
metric_points      (id, resource_type, resource_id, name, value, labels jsonb,
                    observed_at)   -- hypertable candidate if Timescale added
uptime_checks      (id, organization_id →, url, interval, status, last_at)
```

---

## 4. Indexes & performance rules

- Index every FK. Index tenant+scope columns as composite:
  `(organization_id, created_at)` for list pages.
- `audit_logs` and `notifications` are append-heavy: partition by month when
  volume justifies it (Phase 14), start with a covering index.
- DNS/domain lookups: unique index on `(organization_id, name)` where name must
  be unique per tenant.
- No N+1: list endpoints return paginated DTOs built with eager loading;
  serializers receive hydrated models only.

---

## 5. Migration & data-integrity policy

- Migrations are **forward + tested-down** (rollback tested before deploy).
- Destructive migrations (drop column/table) require a two-step deploy: stop
  writing, then remove — never in the same release as the code that uses them.
- All tenant-scoped FKs use `ON DELETE RESTRICT` (or CASCADE only for clearly
  owned children) to prevent orphaned rows from violating isolation.
- Encryption: secrets (`environment_vars.value`, provider credentials) use
  Laravel's encrypted cast with an app-managed key stored outside the DB.
