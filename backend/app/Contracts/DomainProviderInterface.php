<?php

namespace App\Contracts;

/**
 * Port for domain registrars (OVH, Namecheap, OpenSRS, …). OMNEX owns the
 * domain model and lifecycle; a provider only performs registry operations.
 *
 * Implementations must be side-effect free w.r.t. the OMNEX database — the
 * DomainService is the only writer to the `domains` table.
 */
interface DomainProviderInterface
{
    public function name(): string;

    /**
     * Search availability across TLDs.
     *
     * @return array<int, array{
     *     domain: string,
     *     tld: string,
     *     available: bool,
     *     premium: bool,
     *     price: array{amount: float, currency: string, years: int}
     * }>
     */
    public function search(string $query, array $tlds = []): array;

    /**
     * @return array{domain: string, available: bool, reason?: string}
     */
    public function checkAvailability(string $domain): array;

    /**
     * @return array{external_id: string, registered_at: string, expires_at: string}
     */
    public function register(string $domain, array $options = []): array;

    /**
     * @return array{expires_at: string}
     */
    public function renew(string $domain, int $years = 1): array;

    /**
     * @return array{external_id: string, registered_at: string, expires_at: string}
     */
    public function transfer(string $domain, string $authCode): array;

    public function getDetails(string $domain): array;

    public function updateContacts(string $domain, array $contacts): array;

    public function setPrivacy(string $domain, bool $enabled): array;

    public function setTransferLock(string $domain, bool $locked): array;

    public function setNameservers(string $domain, array $nameservers): array;
}
