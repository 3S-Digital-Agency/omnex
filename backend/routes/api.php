<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ContactLeadController;
use App\Http\Controllers\CrossDeviceController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\SshKeyController;
use App\Http\Controllers\StorageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public auth
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/mfa/verify', [AuthController::class, 'verifyMfa']);

    // Passkey / FIDO2 / WebAuthn (public): passwordless sign-in with
    // YubiKey, Touch ID / Face ID, Windows Hello or any FIDO2 authenticator.
    Route::get('/auth/passkey/options', [PasskeyController::class, 'options']);
    Route::post('/auth/passkey/verify', [PasskeyController::class, 'verify']);

    // Cross-device sign-in (PC ↔ phone): the desktop shows a QR code, the
    // phone scans it and authenticates with Face ID / Touch ID / a passkey.
    Route::post('/auth/cross-device/start', [CrossDeviceController::class, 'start']);
    Route::post('/auth/cross-device/approve', [CrossDeviceController::class, 'approve']);

    // Unknown-device verification: confirm a brand-new iPhone / Android /
    // passkey with the 6-digit code e-mailed to the account owner.
    Route::post('/auth/device/verify', [PasskeyController::class, 'verifyDevice']);

    // Social login (OAuth / OpenID Connect). The callback is public: the
    // browser returns from the provider without an auth token. Intent
    // (login vs. account link) is carried by the cached state, not the session.
    Route::get('/auth/social/providers', [SocialAuthController::class, 'providers']);
    Route::post('/auth/social/complete', [SocialAuthController::class, 'complete']);
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback']);

    // Billing webhook is public: the payment provider calls back without an
    // OMNEX session. The tenant is resolved from the webhook event itself.
    Route::post('/billing/webhooks/{provider}', [BillingController::class, 'webhook']);

    // Marketing-site contact leads are public: a visitor submits without an
    // account, and the owning team is notified instead of a tenant. The
    // throttle (per-IP) is part of the anti-spam stack.
    Route::post('/public/leads', [ContactLeadController::class, 'store'])
        ->middleware('throttle:leads');

    // Lead back-office for platform owners.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/public/leads', [ContactLeadController::class, 'index']);
    });

    // Campaign landing pages. The show endpoint is public (marketing links);
    // management is platform-level, gated to owners inside the controller.
    Route::get('/public/landing-pages/{slug}', [LandingPageController::class, 'show']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/landing-pages', [LandingPageController::class, 'index']);
        Route::post('/landing-pages', [LandingPageController::class, 'store']);
        Route::patch('/landing-pages/{landingPage}', [LandingPageController::class, 'update']);
        Route::delete('/landing-pages/{landingPage}', [LandingPageController::class, 'destroy']);
    });

    // Invitation acceptance requires auth but is not tenant-scoped (the target
    // organization is resolved from the token, not the active context).
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/invitations/{invitation}/accept', [InvitationController::class, 'accept']);
    });

    // Session management is user-scoped (not tenant-scoped): a user lists and
    // revokes their own API sessions from any organization context.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions/others', [SessionController::class, 'destroyOthers']);
        Route::delete('/sessions/{session}', [SessionController::class, 'destroy']);
    });

    // Everything below resolves + enforces the active organization.
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateProfile']);
        Route::get('/auth/social/accounts', [SocialAuthController::class, 'index']);
        Route::delete('/auth/social/accounts/{provider}', [SocialAuthController::class, 'destroy']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/mfa/setup', [AuthController::class, 'setupMfa']);
        Route::post('/auth/mfa/confirm', [AuthController::class, 'confirmMfa']);
        Route::post('/auth/mfa/disable', [AuthController::class, 'disableMfa']);

        // Authenticator management (passkeys, YubiKey, biometric devices).
        Route::get('/auth/authenticators', [PasskeyController::class, 'index']);
        Route::get('/auth/passkey/register-options', [PasskeyController::class, 'registerOptions']);
        Route::post('/auth/passkey/register', [PasskeyController::class, 'register']);
        Route::delete('/auth/authenticators/{authenticator}', [PasskeyController::class, 'destroy']);
        Route::patch('/auth/security-level', [PasskeyController::class, 'updateSecurityLevel']);

        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
        Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch']);

        Route::get('/organizations/{organization}/members', [MemberController::class, 'index']);
        Route::patch('/organizations/{organization}/members/{membership}/role', [MemberController::class, 'updateRole']);
        Route::delete('/organizations/{organization}/members/{membership}', [MemberController::class, 'destroy']);

        Route::get('/organizations/{organization}/invitations', [InvitationController::class, 'index']);
        Route::post('/organizations/{organization}/invitations', [InvitationController::class, 'store']);
        Route::delete('/organizations/{organization}/invitations/{invitation}', [InvitationController::class, 'cancel']);

        Route::get('/roles', [RoleController::class, 'index']);

        // Billing (Phase 6). Static routes precede the {subscription} route.
        Route::get('/billing/providers', [BillingController::class, 'providers']);
        Route::get('/billing/plans', [BillingController::class, 'plans']);
        Route::get('/billing/subscription', [BillingController::class, 'subscription']);
        Route::get('/billing/invoices', [BillingController::class, 'invoices']);
        Route::get('/billing/cost-breakdown', [BillingController::class, 'costBreakdown']);
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
        Route::post('/billing/subscriptions/{subscription}/cancel', [BillingController::class, 'cancel']);
        Route::post('/billing/coupons/validate', [BillingController::class, 'validateCoupon']);
        Route::get('/billing/coupons', [BillingController::class, 'coupons']);
        Route::post('/billing/coupons', [BillingController::class, 'storeCoupon']);
        Route::patch('/billing/coupons/{coupon}', [BillingController::class, 'updateCoupon']);
        Route::get('/billing/coupons/{coupon}/redemptions', [BillingController::class, 'couponRedemptions']);
        Route::post('/billing/change-plan', [BillingController::class, 'changePlan']);
        Route::get('/billing/credits', [BillingController::class, 'credits']);
        Route::post('/billing/credits', [BillingController::class, 'addCredits']);

        // Security Center (Phase 7).
        Route::get('/security', [SecurityController::class, 'index']);
        Route::post('/security/scan', [SecurityController::class, 'scan']);
        Route::post('/security/findings/{finding}/dismiss', [SecurityController::class, 'dismiss']);
        Route::post('/security/findings/{finding}/reopen', [SecurityController::class, 'reopen']);
        Route::get('/security/settings', [SecurityController::class, 'settings']);
        Route::patch('/security/settings', [SecurityController::class, 'updateSettings']);
        Route::get('/security/ssl-checks', [SecurityController::class, 'sslChecks']);
        Route::get('/security/history', [SecurityController::class, 'history']);

        Route::get('/audit', [AuditController::class, 'index']);
        Route::get('/activity', [ActivityController::class, 'index']);
        Route::get('/activity/stream', [ActivityController::class, 'stream']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/stream', [NotificationController::class, 'stream']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // Domain engine (Phase 3). Static routes are registered before the
        // {domain} route so they are not captured as a domain id.
        Route::get('/domains/providers', [DomainController::class, 'providers']);
        Route::get('/domains/search', [DomainController::class, 'search']);
        Route::get('/domains/check', [DomainController::class, 'check']);
        Route::post('/domains/transfer', [DomainController::class, 'transfer']);
        Route::get('/domains', [DomainController::class, 'index']);
        Route::post('/domains', [DomainController::class, 'store']);
        Route::get('/domains/{domain}', [DomainController::class, 'show']);
        Route::post('/domains/{domain}/renew', [DomainController::class, 'renew']);
        Route::patch('/domains/{domain}', [DomainController::class, 'update']);

        // DNS engine (Phase 3).
        Route::get('/domains/{domain}/dns', [DnsController::class, 'index']);
        Route::get('/domains/{domain}/dns/dnssec', [DnsController::class, 'dnssec']);
        Route::post('/domains/{domain}/dns/dnssec', [DnsController::class, 'enableDnssec']);
        Route::delete('/domains/{domain}/dns/dnssec', [DnsController::class, 'disableDnssec']);
        Route::get('/domains/{domain}/dns/propagation', [DnsController::class, 'propagation']);
        Route::post('/domains/{domain}/dns/propagation/check', [DnsController::class, 'runPropagationCheck']);
        Route::get('/domains/{domain}/dns/history', [DnsController::class, 'history']);
        Route::get('/domains/{domain}/dns/export', [DnsController::class, 'export']);
        Route::post('/domains/{domain}/dns/import', [DnsController::class, 'import']);
        Route::post('/domains/{domain}/dns/records', [DnsController::class, 'store']);
        Route::patch('/domains/{domain}/dns/records/{record}', [DnsController::class, 'update']);
        Route::delete('/domains/{domain}/dns/records/{record}', [DnsController::class, 'destroy']);
        Route::post('/domains/{domain}/dns/history/{history}/rollback', [DnsController::class, 'rollback']);
        Route::post('/domains/{domain}/dns/templates/{template}', [DnsController::class, 'applyTemplate']);

        // OMNEX Drive (Phase 4). Static routes precede the {folder}/{file}
        // routes so they are not captured as a resource id.
        Route::get('/storage/providers', [StorageController::class, 'providers']);
        Route::get('/storage/usage-history', [StorageController::class, 'usageHistory']);
        Route::get('/storage', [StorageController::class, 'index']);
        Route::get('/storage/trash', [StorageController::class, 'trash']);
        Route::post('/storage/folders', [StorageController::class, 'storeFolder']);
        Route::post('/storage/files', [StorageController::class, 'storeFile']);
        Route::get('/storage/folders/{folder}', [StorageController::class, 'folder']);
        Route::patch('/storage/folders/{folder}', [StorageController::class, 'updateFolder']);
        Route::delete('/storage/folders/{folder}', [StorageController::class, 'destroyFolder']);
        Route::get('/storage/files/{file}', [StorageController::class, 'showFile']);
        Route::get('/storage/files/{file}/download', [StorageController::class, 'downloadFile']);
        Route::patch('/storage/files/{file}', [StorageController::class, 'updateFile']);
        Route::delete('/storage/files/{file}', [StorageController::class, 'trashFile']);
        Route::post('/storage/files/{file}/restore', [StorageController::class, 'restoreFile']);
        Route::delete('/storage/trash/{file}', [StorageController::class, 'destroyFile']);
        Route::get('/storage/files/{file}/versions', [StorageController::class, 'versions']);
        Route::post('/storage/files/{file}/versions/{version}/restore', [StorageController::class, 'restoreVersion']);

        // OMNEX Sites (Phase 5). Static routes precede the {site} route so
        // they are not captured as a site id.
        Route::get('/sites/providers', [SiteController::class, 'providers']);
        Route::get('/sites', [SiteController::class, 'index']);
        Route::post('/sites', [SiteController::class, 'store']);
        Route::get('/sites/{site}', [SiteController::class, 'show']);
        Route::patch('/sites/{site}', [SiteController::class, 'update']);
        Route::delete('/sites/{site}', [SiteController::class, 'destroy']);
        Route::get('/sites/{site}/deployments', [SiteController::class, 'deployments']);
        Route::post('/sites/{site}/deployments', [SiteController::class, 'deploy']);
        Route::get('/sites/{site}/deployments/{deployment}', [SiteController::class, 'showDeployment']);
        Route::post('/sites/{site}/deployments/{deployment}/rollback', [SiteController::class, 'rollback']);

        // OMNEX Cloud (Phase 8). Static routes precede the {server} route so
        // they are not captured as a server id (e.g. /cloud/ssh-keys).
        Route::get('/cloud/providers', [ServerController::class, 'providers']);
        Route::get('/cloud/providers/verify', [ServerController::class, 'verifyProviders']);
        Route::get('/cloud', [ServerController::class, 'index']);
        Route::post('/cloud', [ServerController::class, 'store']);
        Route::get('/cloud/ssh-keys', [SshKeyController::class, 'index']);
        Route::post('/cloud/ssh-keys/generate', [SshKeyController::class, 'generate']);
        Route::post('/cloud/ssh-keys', [SshKeyController::class, 'store']);
        Route::patch('/cloud/ssh-keys/{sshKey}', [SshKeyController::class, 'update']);
        Route::delete('/cloud/ssh-keys/{sshKey}', [SshKeyController::class, 'destroy']);
        Route::post('/cloud/ssh-keys/{sshKey}/unlock', [SshKeyController::class, 'unlock']);
        Route::get('/cloud/{server}', [ServerController::class, 'show']);
        Route::patch('/cloud/{server}', [ServerController::class, 'update']);
        Route::delete('/cloud/{server}', [ServerController::class, 'destroy']);
        Route::post('/cloud/{server}/ssh-key', [ServerController::class, 'installSshKey']);
        Route::get('/cloud/{server}/operations', [ServerController::class, 'operations']);
        Route::get('/cloud/{server}/snapshots', [ServerController::class, 'snapshots']);
        Route::post('/cloud/{server}/snapshots', [ServerController::class, 'storeSnapshot']);
        Route::delete('/cloud/{server}/snapshots/{snapshot}', [ServerController::class, 'destroySnapshot']);
        Route::get('/cloud/{server}/metrics/history', [ServerController::class, 'metricsHistory']);
        Route::get('/cloud/{server}/metrics/stream', [ServerController::class, 'metricsStream']);
        Route::post('/cloud/{server}/start', [ServerController::class, 'start']);
        Route::post('/cloud/{server}/stop', [ServerController::class, 'stop']);
        Route::post('/cloud/{server}/reboot', [ServerController::class, 'reboot']);
        Route::post('/cloud/{server}/rebuild', [ServerController::class, 'rebuild']);
    });
});
