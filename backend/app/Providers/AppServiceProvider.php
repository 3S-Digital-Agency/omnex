<?php

namespace App\Providers;

use App\Contracts\DnsPropagationCheckerInterface;
use App\Contracts\SslCheckerInterface;
use App\Events\DnsRecordChanged;
use App\Events\DnssecChanged;
use App\Events\DomainExpiring;
use App\Events\DomainRegistered;
use App\Events\DomainRenewed;
use App\Events\DomainTransferred;
use App\Events\DomainUpdated;
use App\Listeners\DomainEventAuditor;
use App\Listeners\NotifyExpiringDomain;
use App\Models\User;
use App\Support\Auth\WebAuthnService;
use App\Support\Billing\BillingService;
use App\Support\Billing\PaymentProviderRegistry;
use App\Support\Cloud\ServerProviderRegistry;
use App\Support\Cloud\ServerService;
use App\Support\Cloud\SshKeyService;
use App\Support\Domains\DnsPropagationService;
use App\Support\Domains\DnsProviderRegistry;
use App\Support\Domains\DnsService;
use App\Support\Domains\DomainProviderRegistry;
use App\Support\Domains\DomainService;
use App\Support\Domains\Providers\SandboxDnsPropagationChecker;
use App\Support\Security\Checkers\SandboxSslChecker;
use App\Support\Security\SecurityService;
use App\Support\Sites\SiteProviderRegistry;
use App\Support\Sites\SiteService;
use App\Support\SocialAuth\SocialAuthRegistry;
use App\Support\SocialAuth\SocialAuthService;
use App\Support\Storage\StorageProviderRegistry;
use App\Support\Storage\StorageService;
use App\Support\Streams\InProcessStreamBroker;
use App\Support\Streams\RedisStreamBroker;
use App\Support\Streams\StreamBroker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext);
        $this->app->singleton(DomainProviderRegistry::class, fn () => new DomainProviderRegistry);
        $this->app->singleton(DnsProviderRegistry::class, fn () => new DnsProviderRegistry);
        $this->app->bind(DnsPropagationCheckerInterface::class, fn () => new SandboxDnsPropagationChecker);
        $this->app->bind(SslCheckerInterface::class, fn () => new SandboxSslChecker(
            (int) config('omnex.security.ssl_warning_days', 30),
        ));
        $this->app->singleton(DnsPropagationService::class);
        $this->app->singleton(DomainService::class);
        $this->app->singleton(DnsService::class);
        $this->app->singleton(SocialAuthRegistry::class, fn () => new SocialAuthRegistry);
        $this->app->singleton(SocialAuthService::class);
        $this->app->singleton(StorageProviderRegistry::class, fn () => new StorageProviderRegistry);
        $this->app->singleton(StorageService::class);
        $this->app->singleton(SiteProviderRegistry::class, fn () => new SiteProviderRegistry);
        $this->app->singleton(SiteService::class);
        $this->app->singleton(ServerProviderRegistry::class, fn () => new ServerProviderRegistry);
        $this->app->singleton(ServerService::class);
        $this->app->singleton(SshKeyService::class);
        $this->app->singleton(SecurityService::class);
        $this->app->singleton(WebAuthnService::class);
        $this->app->singleton(WebAuthnService::class);
        $this->app->singleton(PaymentProviderRegistry::class, fn () => new PaymentProviderRegistry);
        $this->app->singleton(BillingService::class);
        $this->app->singleton(StreamBroker::class, function () {
            $driver = config('omnex.streams.driver', 'inprocess');

            if ($driver === 'redis') {
                return new RedisStreamBroker(
                    (string) config('omnex.streams.prefix', 'omnex:'),
                    (string) config('omnex.streams.redis_connection', 'default'),
                );
            }

            return new InProcessStreamBroker;
        });
    }

    public function boot(): void
    {
        // Public contact-lead endpoint: per-IP throttle (anti-spam). The
        // limiter is keyed on IP so a bot farm cannot flood the inbox.
        RateLimiter::for('leads', function (Request $request) {
            return Limit::perMinute((int) config('omnex.leads.rate_limit_max', 5))
                ->by($request->ip() ?? 'unknown');
        });

        // Default-deny authorization. A permission key (e.g. "organizations.manage")
        // is checked first via the user's active-organization role. If it is not
        // granted, the gate falls through to model policies. If neither grants
        // access, the request is denied — nothing is allowed implicitly.
        Gate::before(function (?User $user, string $ability) {
            if ($user !== null && $user->hasPermission($ability)) {
                return true;
            }

            return null;
        });

        // Domain/DNS event bus: services publish, listeners side-effect
        // (audit stream, expiration notifications). Keep handlers synchronous
        // so the tenant/user context is intact when they run.
        Event::listen(DomainRegistered::class, DomainEventAuditor::class);
        Event::listen(DomainRenewed::class, DomainEventAuditor::class);
        Event::listen(DomainTransferred::class, DomainEventAuditor::class);
        Event::listen(DomainUpdated::class, DomainEventAuditor::class);
        Event::listen(DomainExpiring::class, DomainEventAuditor::class);
        Event::listen(DomainExpiring::class, NotifyExpiringDomain::class);
        Event::listen(DnsRecordChanged::class, DomainEventAuditor::class);
        Event::listen(DnssecChanged::class, DomainEventAuditor::class);
    }
}
