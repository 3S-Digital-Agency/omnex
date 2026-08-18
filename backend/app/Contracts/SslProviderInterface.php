<?php

namespace App\Contracts;

/**
 * Port for TLS certificate authorities / edge platforms (Let's Encrypt,
 * Cloudflare, ZeroSSL, AWS ACM, …). OMNEX owns the certificate lifecycle and
 * its `ssl_certificates` record; a provider only issues, renews and revokes a
 * certificate for a domain and reports its status.
 *
 * This is distinct from SslCheckerInterface, which only *monitors* an existing
 * certificate for the security score. Issuance lives here; monitoring reads
 * the persisted record.
 */
interface SslProviderInterface
{
    public function name(): string;

    /** Human-readable provider name (for the UI / provider selector). */
    public function label(): string;

    /**
     * Whether the provider has the credentials required to reach a real CA or
     * edge platform. The sandbox is always configured; real providers activate
     * only once their credentials are set.
     */
    public function isConfigured(): bool;

    /**
     * Issue (or ensure) a certificate for a domain.
     *
     * @param  array<string, mixed>  $options
     * @return array{external_id: string, status: string, issuer: string, issued_at: string, expires_at: string, auto_renew: bool, certificate_pem?: string, private_key_pem?: string}
     */
    public function issue(string $domain, array $options = []): array;

    /**
     * Renew an existing certificate.
     *
     * @param  array<string, mixed>  $certificate  persisted provider fields (external_id, certificate_pem, …)
     * @return array{external_id: string, status: string, issued_at: string, expires_at: string, certificate_pem?: string, private_key_pem?: string}
     */
    public function renew(string $domain, array $certificate = []): array;

    /**
     * Revoke (or disable) a certificate.
     *
     * @param  array<string, mixed>  $certificate
     */
    public function revoke(string $domain, array $certificate = []): array;

    /**
     * Current certificate status on the provider.
     *
     * @param  array<string, mixed>  $certificate
     * @return array{status: string, expires_at: ?string}
     */
    public function status(string $domain, array $certificate = []): array;
}
