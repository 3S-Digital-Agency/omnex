<?php

namespace App\Support\Domains\Providers;

use App\Contracts\DomainProviderInterface;
use App\Support\Domains\DomainProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Bring-your-own registrar behind DomainProviderInterface.
 *
 * Points at any HTTP/JSON registrar gateway the operator configures
 * (config/customregistrar.php). Requests are POSTed as JSON with an
 * optional Bearer token; the gateway replies with the same shapes the
 * interface expects under a `data` key:
 *
 *   {"command": "check", "domain": "x.tld", ...}
 *   → {"data": {"domain": "x.tld", "available": true}}
 *
 * Commands: search, check, register, renew, transfer, getDetails,
 * updateContacts, setPrivacy, setTransferLock, setNameservers.
 */
final class CustomDomainProvider implements DomainProviderInterface
{
    public function name(): string
    {
        return 'custom';
    }

    public function label(): string
    {
        return 'Custom';
    }

    public function isConfigured(): bool
    {
        return $this->endpoint() !== '';
    }

    public function search(string $query, array $tlds = []): array
    {
        $query = Str::slug($query);
        $tlds = $tlds === [] ? ['com', 'io', 'dev', 'net', 'org'] : $tlds;

        $data = $this->call('search', ['query' => $query, 'tlds' => $tlds]);

        return $this->expectList($data);
    }

    public function checkAvailability(string $domain): array
    {
        $domain = $this->normalize($domain);

        return $this->call('check', ['domain' => $domain]);
    }

    public function register(string $domain, array $options = []): array
    {
        $domain = $this->normalize($domain);

        return $this->call('register', ['domain' => $domain, 'options' => $options]);
    }

    public function renew(string $domain, int $years = 1): array
    {
        $domain = $this->normalize($domain);

        return $this->call('renew', ['domain' => $domain, 'years' => $years]);
    }

    public function transfer(string $domain, string $authCode): array
    {
        $domain = $this->normalize($domain);

        return $this->call('transfer', ['domain' => $domain, 'auth_code' => $authCode]);
    }

    public function getDetails(string $domain): array
    {
        $domain = $this->normalize($domain);

        return $this->call('getDetails', ['domain' => $domain]);
    }

    public function updateContacts(string $domain, array $contacts): array
    {
        $domain = $this->normalize($domain);

        return $this->call('updateContacts', ['domain' => $domain, 'contacts' => $contacts]);
    }

    public function setPrivacy(string $domain, bool $enabled): array
    {
        $domain = $this->normalize($domain);

        return $this->call('setPrivacy', ['domain' => $domain, 'enabled' => $enabled]);
    }

    public function setTransferLock(string $domain, bool $locked): array
    {
        $domain = $this->normalize($domain);

        return $this->call('setTransferLock', ['domain' => $domain, 'locked' => $locked]);
    }

    public function setNameservers(string $domain, array $nameservers): array
    {
        $domain = $this->normalize($domain);

        return $this->call('setNameservers', ['domain' => $domain, 'nameservers' => $nameservers]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $command, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new DomainProviderException('Custom registrar is not configured (missing endpoint).');
        }

        $response = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
            ->when($this->apiKey() !== '', fn ($http) => $http->withToken($this->apiKey()))
            ->post($this->endpoint(), array_merge(['command' => $command], $payload));

        if ($response->failed()) {
            $message = $response->json()['error'] ?? "Custom registrar failed with HTTP {$response->status()}.";

            throw new DomainProviderException((string) $message);
        }

        $json = $response->json();

        if (is_array($json['data'] ?? null)) {
            return $json['data'];
        }

        if (is_array($json)) {
            return $json;
        }

        throw new DomainProviderException('Custom registrar returned an invalid response.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expectList(mixed $data): array
    {
        if (! is_array($data)) {
            throw new DomainProviderException('Custom registrar returned an invalid search response.');
        }

        return array_values($data);
    }

    private function endpoint(): string
    {
        return rtrim((string) config('customregistrar.endpoint'), '/');
    }

    private function apiKey(): string
    {
        return (string) config('customregistrar.api_key');
    }

    private function normalize(string $domain): string
    {
        return strtolower(trim($domain, '. '));
    }
}
