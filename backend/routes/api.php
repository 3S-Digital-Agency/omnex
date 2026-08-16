<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public auth
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/mfa/verify', [AuthController::class, 'verifyMfa']);

    // Social login (OAuth / OpenID Connect). The callback is public: the
    // browser returns from the provider without an auth token. Intent
    // (login vs. account link) is carried by the cached state, not the session.
    Route::get('/auth/social/providers', [SocialAuthController::class, 'providers']);
    Route::post('/auth/social/complete', [SocialAuthController::class, 'complete']);
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback']);

    // Invitation acceptance requires auth but is not tenant-scoped (the target
    // organization is resolved from the token, not the active context).
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/invitations/{invitation}/accept', [InvitationController::class, 'accept']);
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

        Route::get('/audit', [AuditController::class, 'index']);
        Route::get('/activity', [ActivityController::class, 'index']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // Domain engine (Phase 3). Search/check/transfer are registered before
        // the {domain} route so they are not captured as a domain id.
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
    });
});
