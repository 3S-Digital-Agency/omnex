<?php

namespace App\Contracts;

/**
 * Port for managed DNS providers (Cloudflare, OVH, Route 53, …). OMNEX owns the
 * zone and record model; the provider only syncs records to the DNS plane.
 */
interface DnsProviderInterface
{
    public function name(): string;

    /**
     * @return array{external_id: string}
     */
    public function createZone(string $domain): array;

    public function deleteZone(string $domain): array;

    /**
     * @param  array{name: string, type: string, content: string, ttl: int, priority: ?int, proxied: bool}  $record
     * @return array{external_id: string}
     */
    public function createRecord(string $domain, array $record): array;

    /**
     * @param  array{name: string, type: string, content: string, ttl: int, priority: ?int, proxied: bool}  $record
     */
    public function updateRecord(string $domain, array $record): array;

    /**
     * @param  array{name: string, type: string, content: string, ttl: int, priority: ?int, proxied: bool}  $record
     */
    public function deleteRecord(string $domain, array $record): array;

    /**
     * Sign the zone and return the DS record(s) the registrar must publish in
     * the parent zone to complete the chain of trust.
     *
     * @return array<int, array{key_tag: int, algorithm: int, digest_type: int, digest: string}>
     */
    public function enableDnssec(string $domain): array;

    /**
     * Stop signing the zone. The caller is responsible for removing the DS
     * records from the parent zone.
     */
    public function disableDnssec(string $domain): array;
}
