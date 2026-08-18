# OMNEX — Providers, Perks & Multi-tenant Architecture

> Living reference for the cross-cutting "pluggable infrastructure" layer.
> Covers dynamic provider switching, SSL issuance, feature flags/perks and
> organization provisioning. Everything here is implemented in code, not
> simulated: the tenant scope and the Strategy/Factory registries are the real
> isolation and dispatch layers.

---

## 1. Contracts (ports)

Every external system is behind a PHP interface in `app/Contracts`. OMNEX owns
the domain model + lifecycle; the provider only talks to the outside world.

| Interface | Bricks (adapters) | Config default |
| --- | --- | --- |
| `DomainProviderInterface` | sandbox, namecheap, ovh, custom | `omnex.domain.provider` |
| `DnsProviderInterface` | sandbox, cloudflare | `omnex.domain.dns_provider` |
| `SiteProviderInterface` | sandbox, custom, cloudflare (Pages) | `omnex.sites.provider` |
| `ServerProviderInterface` | sandbox, hetzner, digitalocean, custom | `omnex.cloud.provider` |
| `StorageProviderInterface` | sandbox, s3, r2 | `omnex.storage.provider` |
| `SslProviderInterface` | sandbox, cloudflare (Universal SSL) | `omnex.ssl.provider` |
| `PaymentProviderInterface` | sandbox, stripe | `omnex.billing.provider` |
| `SslCheckerInterface` | sandbox (monitoring, read-only) | `omnex.security.ssl_checker` |
| `DnsPropagationCheckerInterface` | sandbox | — |

All provider interfaces share three members: `name()`, `label()` and
`isConfigured()` — so every registry can render a uniform selector with a
"configured / not configured" state.

---

## 2. Registries (Strategy / Factory)

Each brick has a `*ProviderRegistry` (e.g. `SslProviderRegistry`) that maps a
provider `name()` to its adapter and is registered as a singleton in
`AppServiceProvider`. Adding a provider = one new adapter class + one
`$this->register(...)` line. Services never `new` an adapter — they resolve it
from the registry by name.

```php
final class SslService
{
    public function __construct(private SslProviderRegistry $providers) {}

    private function provider(?string $name = null): SslProviderInterface
    {
        $provider = $this->providers->get($name ?? $this->activeProviderName());
        if (! $provider->isConfigured()) {
            throw new SslProviderException("The [{$provider->label()}] SSL provider is not configured.");
        }
        return $provider;
    }
}
```

---

## 3. Dynamic provider switching (`ResolvesTenantProvider`)

`app/Support/Providers/ResolvesTenantProvider.php` is the single trait that
turns a registry into a *switchable* provider. Every service (storage, sites,
cloud, domains, DNS, SSL) uses it. For the active organization the effective
provider is resolved in priority order:

1. **Organization override** — `organizations.settings[<settings key>]`
   (e.g. `storage_provider`, `cloud_provider`, `ssl_provider`), persisted via
   the service's `setProvider()`.
2. **Environment default** — the `config()` key (e.g. `OMNEX_CLOUD_PROVIDER`).

```php
trait ResolvesTenantProvider
{
    abstract protected function providerConfigKey(): string;   // omnex.cloud.provider
    abstract protected function providerSettingsKey(): string; // cloud_provider

    public function activeProviderName(): string
    {
        $org = app(TenantContext::class)->organization();
        return (string) ($org?->settings[$this->providerSettingsKey()] ?? config($this->providerConfigKey(), 'sandbox'));
    }

    public function activeProvider(): array { /* name/label/configured/active */ }
    public function setProvider(string $name): array { /* validate + persist to org settings */ }
}
```

**REST surface** (all under `auth:sanctum` + `tenant`):

- `GET  /{service}/providers` — list adapters (name/label/configured)
- `GET  /{service}/provider` — active provider (`active: true`)
- `PATCH /{service}/provider` — switch (validates the name, persists to org)

`{service}` ∈ `storage`, `sites`, `cloud`, `domains`, `dns`, `ssl`.

---

## 4. Multi-tenant isolation

- `TenantContext` holds the active `organization_id` for the request (resolved
  by the `ResolveTenant` middleware from the authenticated user + the
  `X-Organization` header).
- `TenantScope` (global Eloquent scope) auto-filters every `HasTenant` model by
  `organization_id`. PostgreSQL Row-Level Security is the second, DB-enforced
  layer (opt-in via `OMNEX_ENFORCE_RLS`).
- Per-organization **configuration** (provider assignments + feature overrides)
  lives in the `organizations.settings` JSONB column — provider *names*, never
  credentials. Credentials stay in server-side config/secrets.

---

## 5. SSL provider abstraction

`SslProviderInterface` is the issuance port (distinct from the read-only
`SslCheckerInterface` used by the Security Center):

```php
interface SslProviderInterface
{
    public function name(): string;
    public function label(): string;
    public function isConfigured(): bool;
    public function issue(string $domain, array $options = []): array;
    public function renew(string $domain, array $certificate = []): array;
    public function revoke(string $domain, array $certificate = []): array;
    public function status(string $domain, array $certificate = []): array;
}
```

- `SandboxSslProvider` — deterministic, always configured (local/test).
- `CloudflareSslProvider` — maps issuance onto Cloudflare Universal SSL
  (`PATCH /zones/{id}/ssl/universal/settings {enabled}`); the edge certificate
  renews automatically at Cloudflare.
- `SslService` owns the `ssl_certificates` record (tenant-scoped, one per
  domain) and the audit trail. `DomainService` auto-issues on registration /
  transfer and renews on domain renewal — gated by the `ssl` perk and
  `omnex.ssl.auto_issue`.

REST: `GET/POST/DELETE /domains/{domain}/ssl`, `POST /domains/{domain}/ssl/renew`,
`GET /ssl/providers`, `GET/PATCH /ssl/provider`, `GET /ssl/certificates`.

---

## 6. Feature flags / perks

`FeatureFlagService` resolves every flag in `config('omnex.features')` for the
active organization, in priority order:

1. `organizations.settings.features.<flag>` (explicit override)
2. `tiers[<plan_tier>]` (plan default)
3. the platform `default`

Flags are typed (`boolean` / `number`, where `0` = unlimited) and exposed as
`{ key, label, type, value, enabled, source }`. Enforcement happens in two
places so a disabled module cannot be reached by accident:

- **Routes** — `feature:<flag>` middleware (e.g. the TLS mutation endpoints are
  `feature:ssl`), returning 403 when disabled.
- **UI** — `useFeatures()` feeds the sidebar/navigation; a disabled module is
  hidden (the engine also checks the flag, e.g. auto-issue skips for free
  tiers).

REST: `GET /features`, `PATCH /features/{flag}`, `DELETE /features/{flag}/override`
(override requires `organizations.manage`).

---

## 7. Organization provisioning

`OrganizationService::create()` is the single writer. Creating an organization
atomically:

- creates the `Organization` (plan `free`, `status` active),
- creates the owning `Membership` (`owner` role),
- writes `settings` with every provider brick assigned to its sandbox default
  and an empty `features` map (plan-tier perks apply).

The result is a tenant that is **immediately functional** — no follow-up step
required to make storage, DNS, SSL, sites, cloud or perks resolve.

---

## 8. Use case — creating an organization with providers + perks

1. `POST /api/v1/organizations {"name": "Nova Labs"}` → `OrganizationService`
   provisions `settings` = `{storage_provider, site_provider, cloud_provider,
   domain_provider, dns_provider, ssl_provider, features: []}` + owner
   membership.
2. `GET /api/v1/features` → plan-tier defaults (`free`): domains/drive/security/
   billing/passkeys on; cloud/sites/ssl/snapshots off; `domain_limit = 1`.
3. Upgrade to `pro` (`POST /billing/subscribe`) → `plan_tier` becomes `pro`;
   the same `/features` endpoint now reports cloud/sites/ssl/real_providers on
   and unlimited quotas.
4. Switch a brick: `PATCH /api/v1/storage/provider {"name":"r2"}` persists
   `settings.storage_provider = "r2"` for **this organization only**.
5. Register a domain: `POST /domains` → zone + (because `ssl` is on for `pro`)
   an auto-issued `ssl_certificates` row via the active SSL provider.
6. A `free` org hitting `POST /domains/{id}/ssl` gets **403** (`feature:ssl`),
   while its `/features` payload still lets the UI hide the control.

Everything above is backed by the Pest suite (`ProviderSwitchingTest`,
`SslProviderTest`, `FeatureFlagTest`, `OrganizationTest`).
