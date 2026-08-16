<?php

namespace App\Support\Domains\Providers;

use App\Contracts\DomainProviderInterface;
use App\Support\Domains\DomainProviderException;
use App\Support\Domains\DomainUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;

/**
 * Real Namecheap registrar behind DomainProviderInterface.
 *
 * Talks to the Namecheap XML API (api.namecheap.com/xml.response) over
 * HTTPS via the Laravel HTTP client (Guzzle). Activates only when
 * OMNEX_DOMAIN_PROVIDER=namecheap AND the API credentials are set
 * (config/namecheap.php). Registrant contacts come from config or the
 * `contacts` option — Namecheap refuses registrations without them.
 *
 * Prices returned by `search()` are estimates from a static table; the
 * authoritative price is the checkout API (namecheap.domains.getPricing).
 */
final class NamecheapDomainProvider implements DomainProviderInterface
{
    private const XML_NS = 'http://api.namecheap.com/xml.response';

    private const PRICES = [
        'com' => 10.69,
        'net' => 12.28,
        'org' => 10.29,
        'io' => 38.99,
        'dev' => 14.99,
        'co' => 24.99,
        'app' => 14.99,
        'cloud' => 14.99,
        'ca' => 10.99,
    ];

    /** OMNEX contact keys → Namecheap API fields. */
    private const CONTACT_FIELDS = [
        'first_name' => 'FirstName',
        'last_name' => 'LastName',
        'address1' => 'Address1',
        'address2' => 'Address2',
        'city' => 'City',
        'state_province' => 'StateProvince',
        'postal_code' => 'PostalCode',
        'country' => 'Country',
        'phone' => 'Phone',
        'email_address' => 'EmailAddress',
    ];

    private const REQUIRED_CONTACT_FIELDS = [
        'FirstName', 'LastName', 'Address1', 'City', 'StateProvince',
        'PostalCode', 'Country', 'Phone', 'EmailAddress',
    ];

    public function name(): string
    {
        return 'namecheap';
    }

    public function label(): string
    {
        return 'Namecheap';
    }

    public function isConfigured(): bool
    {
        $c = $this->config();

        return $c['api_user'] !== '' && $c['api_key'] !== '' && $c['username'] !== '';
    }

    public function search(string $query, array $tlds = []): array
    {
        $query = Str::slug($query);
        $tlds = $tlds === [] ? ['com', 'io', 'dev', 'net', 'org'] : $tlds;

        $domains = array_map(fn (string $tld) => $query.'.'.strtolower($tld), $tlds);

        $response = $this->request('namecheap.domains.check', ['DomainList' => implode(',', $domains)]);

        $results = [];
        foreach ($response->DomainCheckResult ?? [] as $node) {
            $domain = (string) $node['Domain'];
            $tld = strtolower(substr($domain, (int) strrpos($domain, '.') + 1));
            $premium = strtolower((string) $node['Premium']) === 'true';

            $results[] = [
                'domain' => $domain,
                'tld' => $tld,
                'available' => strtolower((string) $node['Available']) === 'true',
                'premium' => $premium,
                'price' => [
                    'amount' => $this->priceFor($tld, $premium),
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

        $response = $this->request('namecheap.domains.check', ['DomainList' => $domain]);

        $node = $response->DomainCheckResult ?? null;
        if ($node === null) {
            throw new DomainProviderException('Namecheap did not return an availability result.');
        }

        return [
            'domain' => $domain,
            'available' => strtolower((string) $node['Available']) === 'true',
        ];
    }

    public function register(string $domain, array $options = []): array
    {
        $domain = $this->normalize($domain);
        $years = max(1, (int) ($options['years'] ?? 1));

        $response = $this->request('namecheap.domains.create', array_merge([
            'DomainName' => $domain,
            'Years' => $years,
        ], $this->contactParams($options['contacts'] ?? [])));

        $node = $response->DomainCreateResult ?? null;
        if ($node === null || strtolower((string) $node['Registered']) !== 'true') {
            throw new DomainProviderException('Namecheap did not confirm the domain registration.');
        }

        return [
            'external_id' => (string) ($node['Domain'] ?? $domain),
            'registered_at' => now()->toIso8601String(),
            'expires_at' => now()->addYears($years)->toIso8601String(),
        ];
    }

    public function renew(string $domain, int $years = 1): array
    {
        $domain = $this->normalize($domain);
        $years = max(1, $years);

        $response = $this->request('namecheap.domains.renew', [
            'DomainName' => $domain,
            'Years' => $years,
        ]);

        $node = $response->DomainRenewResult ?? null;
        if ($node === null || strtolower((string) $node['Renewed']) !== 'true') {
            throw new DomainProviderException('Namecheap did not confirm the renewal.');
        }

        return ['expires_at' => now()->addYears($years)->toIso8601String()];
    }

    public function transfer(string $domain, string $authCode): array
    {
        $domain = $this->normalize($domain);

        $response = $this->request('namecheap.domains.transfer', array_merge([
            'DomainName' => $domain,
            'TransferCode' => $authCode,
        ], $this->contactParams([])));

        $node = $response->DomainTransferResult ?? null;
        if ($node === null || strtolower((string) $node['IsSuccess']) !== 'true') {
            throw new DomainProviderException('Namecheap did not accept the domain transfer.');
        }

        return [
            'external_id' => (string) ($node['Domain'] ?? $domain),
            'registered_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
        ];
    }

    public function getDetails(string $domain): array
    {
        $domain = $this->normalize($domain);

        $response = $this->request('namecheap.domains.getInfo', ['DomainName' => $domain]);

        $node = $response->DomainGetInfoResult ?? null;
        if ($node === null) {
            throw new DomainProviderException('Namecheap did not return domain details.');
        }

        $nameservers = [];
        foreach ($node->NameServers->NameServer ?? [] as $ns) {
            $nameservers[] = trim((string) $ns);
        }

        return [
            'domain' => $domain,
            'registrar' => 'Namecheap',
            'status' => strtolower((string) $node['Status']),
            'external_id' => (string) $node['ID'],
            'transfer_lock' => strtolower((string) $node['Locked']) === 'true',
            'privacy_enabled' => strtolower((string) $node['WhoisGuard']) === 'enabled',
            'nameservers' => $nameservers,
            'expires_at' => trim((string) ($node->DomainDetails->ExpiredDate ?? '')),
        ];
    }

    public function updateContacts(string $domain, array $contacts): array
    {
        $domain = $this->normalize($domain);

        $response = $this->request('namecheap.domains.setContacts', array_merge(
            ['DomainName' => $domain],
            $this->contactParams($contacts),
        ));

        $node = $response->DomainSetContactsResult ?? null;
        if ($node === null || strtolower((string) $node['IsSuccess']) !== 'true') {
            throw new DomainProviderException('Namecheap did not update the domain contacts.');
        }

        return ['contacts' => $contacts];
    }

    public function setPrivacy(string $domain, bool $enabled): array
    {
        $domain = $this->normalize($domain);

        $response = $this->request('namecheap.domains.setWhoisGuard', [
            'DomainName' => $domain,
            'WgEnabled' => $enabled ? 'ENABLE' : 'DISABLE',
        ]);

        $node = $response->DomainSetWhoisGuardResult ?? null;
        if ($node === null || strtolower((string) $node['IsSuccess']) !== 'true') {
            throw new DomainProviderException('Namecheap did not update WhoisGuard.');
        }

        return ['privacy_protection' => $enabled];
    }

    public function setTransferLock(string $domain, bool $locked): array
    {
        $domain = $this->normalize($domain);

        $response = $this->request('namecheap.domains.setRegistrarLock', [
            'DomainName' => $domain,
            'LockAction' => $locked ? 'LOCK' : 'UNLOCK',
        ]);

        $node = $response->DomainSetRegistrarLockResult ?? null;
        if ($node === null || strtolower((string) $node['IsSuccess']) !== 'true') {
            throw new DomainProviderException('Namecheap did not update the registrar lock.');
        }

        return ['transfer_lock' => $locked];
    }

    public function setNameservers(string $domain, array $nameservers): array
    {
        $domain = $this->normalize($domain);

        $params = ['DomainName' => $domain];
        foreach (array_slice(array_values($nameservers), 0, 5) as $index => $nameserver) {
            $params['NameServer'.($index + 1)] = $nameserver;
        }

        $response = $this->request('namecheap.domains.ns.update', $params);

        $node = $response->DomainNsUpdateResult ?? null;
        if ($node === null || strtolower((string) $node['IsSuccess']) !== 'true') {
            throw new DomainProviderException('Namecheap did not update the nameservers.');
        }

        return ['nameservers' => array_values($nameservers)];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function request(string $command, array $params = []): SimpleXMLElement
    {
        $this->assertConfigured();

        $c = $this->config();

        $query = array_merge([
            'ApiUser' => $c['api_user'],
            'ApiKey' => $c['api_key'],
            'UserName' => $c['username'],
            'ClientIp' => $c['client_ip'],
        ], ['Command' => $command], $params);

        $response = Http::timeout(30)->get($c['endpoint'], $query);

        if ($response->failed()) {
            throw new DomainProviderException("Namecheap request failed with HTTP {$response->status()}.");
        }

        $xml = simplexml_load_string($response->body());
        if ($xml === false) {
            throw new DomainProviderException('Namecheap returned malformed XML.');
        }

        if (strtoupper((string) $xml['Status']) === 'ERROR') {
            $messages = [];
            foreach ($xml->Errors->Error ?? [] as $error) {
                $messages[] = trim((string) $error);
            }

            $message = implode('; ', $messages);

            // A domain that is no longer available is a normal state, not an
            // infrastructure failure — map it to the 422 path.
            if (str_contains(strtolower($message), 'not available') || str_contains(strtolower($message), 'unavailable')) {
                throw new DomainUnavailableException($message);
            }

            throw new DomainProviderException('Namecheap error: '.$message);
        }

        return $xml->CommandResponse;
    }

    /**
     * @return array{endpoint: string, api_user: string, api_key: string, username: string, client_ip: string}
     */
    private function config(): array
    {
        return [
            'endpoint' => (string) config('namecheap.endpoint'),
            'api_user' => (string) config('namecheap.api_user'),
            'api_key' => (string) config('namecheap.api_key'),
            'username' => (string) config('namecheap.username'),
            'client_ip' => (string) config('namecheap.client_ip'),
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new DomainProviderException('Namecheap provider is not configured (missing API credentials).');
        }
    }

    /**
     * @param  array<string, mixed>  $contacts
     * @return array<string, string>
     */
    private function contactParams(array $contacts): array
    {
        $merged = array_merge(config('namecheap.registrant', []), $contacts);

        $params = [];
        foreach (self::CONTACT_FIELDS as $omnexKey => $namecheapField) {
            if (($merged[$omnexKey] ?? '') !== '') {
                $params[$namecheapField] = $merged[$omnexKey];
            }
        }

        foreach (self::REQUIRED_CONTACT_FIELDS as $field) {
            if (($params[$field] ?? '') === '') {
                throw new DomainProviderException("Namecheap requires the [{$field}] registrant contact.");
            }
        }

        return $params;
    }

    private function priceFor(string $tld, bool $premium = false): float
    {
        $base = self::PRICES[strtolower($tld)] ?? 14.99;

        return $premium ? round($base * 2, 2) : $base;
    }

    private function normalize(string $domain): string
    {
        return strtolower(trim($domain, '. '));
    }
}
