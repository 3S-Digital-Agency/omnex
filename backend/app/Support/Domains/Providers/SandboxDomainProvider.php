<?php

namespace App\Support\Domains\Providers;

use App\Contracts\DomainProviderInterface;
use App\Support\Domains\DomainUnavailableException;
use Illuminate\Support\Str;

/**
 * Deterministic in-memory registrar for local/test environments. No domain is
 * ever registered with a real registry, and availability is derived from a
 * stable hash plus an explicit reserved list — never random, so tests and the
 * UI are reproducible.
 */
final class SandboxDomainProvider implements DomainProviderInterface
{
    private const RESERVED = [
        'omnex.dev',
        'omnex.io',
        'nexus.com',
        'cloud.com',
        'google.com',
        'apple.com',
        'facebook.com',
        'amazon.com',
        'microsoft.com',
    ];

    private const PRICES = [
        'com' => 12.99,
        'net' => 14.99,
        'org' => 11.99,
        'io' => 49.99,
        'dev' => 14.99,
        'co' => 29.99,
        'app' => 19.99,
        'cloud' => 19.99,
        'ca' => 13.99,
    ];

    public function name(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function search(string $query, array $tlds = []): array
    {
        $query = Str::slug($query);
        $tlds = $tlds === [] ? ['com', 'io', 'dev', 'net', 'org'] : $tlds;

        $results = [];
        foreach ($tlds as $tld) {
            $domain = $query.'.'.strtolower($tld);
            $results[] = [
                'domain' => $domain,
                'tld' => strtolower($tld),
                'available' => $this->isAvailable($domain),
                'premium' => $this->isPremium($tld),
                'price' => [
                    'amount' => $this->priceFor($tld),
                    'currency' => 'USD',
                    'years' => 1,
                ],
            ];
        }

        return $results;
    }

    public function checkAvailability(string $domain): array
    {
        $domain = $this->normalize($domain);

        return [
            'domain' => $domain,
            'available' => $this->isAvailable($domain),
        ];
    }

    public function register(string $domain, array $options = []): array
    {
        $domain = $this->normalize($domain);

        if (! $this->isAvailable($domain)) {
            throw new DomainUnavailableException("The domain [{$domain}] is not available.");
        }

        $years = max(1, (int) ($options['years'] ?? 1));

        return [
            'external_id' => 'sandbox-dom-'.Str::lower(Str::random(12)),
            'registered_at' => now()->toIso8601String(),
            'expires_at' => now()->addYears($years)->toIso8601String(),
        ];
    }

    public function renew(string $domain, int $years = 1): array
    {
        $domain = $this->normalize($domain);

        return [
            'expires_at' => now()->addYears(max(1, $years))->toIso8601String(),
        ];
    }

    public function transfer(string $domain, string $authCode): array
    {
        $domain = $this->normalize($domain);

        if ($authCode === '' || strlen($authCode) < 6) {
            throw new DomainUnavailableException('A valid authorization code is required to transfer a domain.');
        }

        return [
            'external_id' => 'sandbox-dom-'.Str::lower(Str::random(12)),
            'registered_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
        ];
    }

    public function getDetails(string $domain): array
    {
        $domain = $this->normalize($domain);

        return [
            'domain' => $domain,
            'registrar' => 'OMNEX Sandbox Registrar',
            'status' => $this->isAvailable($domain) ? 'available' : 'registered',
        ];
    }

    public function updateContacts(string $domain, array $contacts): array
    {
        return ['contacts' => $contacts];
    }

    public function setPrivacy(string $domain, bool $enabled): array
    {
        return ['privacy_protection' => $enabled];
    }

    public function setTransferLock(string $domain, bool $locked): array
    {
        return ['transfer_lock' => $locked];
    }

    public function setNameservers(string $domain, array $nameservers): array
    {
        return ['nameservers' => $nameservers];
    }

    private function isAvailable(string $domain): bool
    {
        $domain = strtolower($domain);

        if (in_array($domain, self::RESERVED, true)) {
            return false;
        }

        // Stable pseudo-random "taken" set (~20%).
        return crc32($domain) % 5 !== 0;
    }

    private function isPremium(string $tld): bool
    {
        return in_array(strtolower($tld), ['io', 'co', 'app', 'cloud'], true);
    }

    private function priceFor(string $tld): float
    {
        return self::PRICES[strtolower($tld)] ?? 14.99;
    }

    private function normalize(string $domain): string
    {
        return strtolower(trim($domain, '. '));
    }
}
