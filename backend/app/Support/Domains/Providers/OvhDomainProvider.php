<?php

namespace App\Support\Domains\Providers;

use App\Contracts\DomainProviderInterface;
use App\Support\Domains\DomainProviderException;
use App\Support\Domains\DomainUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Real OVHcloud registrar behind DomainProviderInterface.
 *
 * Authenticates against the OVHcloud public API (eu.api.ovh.com) with the
 * standard application-key / consumer-key time-based signature and performs
 * domain operations through the order-cart flow (availability, registration,
 * transfer) plus the /domain service endpoints (details, contacts, Whois
 * privacy, transfer lock, nameservers, renewal).
 *
 * Activates only when NEXUS_DOMAIN_PROVIDER=ovh AND the credentials are set
 * (config/ovh.php). Prices come from the cart offer response.
 */
final class OvhDomainProvider implements DomainProviderInterface
{
    public function name(): string
    {
        return 'ovh';
    }

    public function label(): string
    {
        return 'OVH';
    }

    public function isConfigured(): bool
    {
        $c = $this->config();

        return $c['application_key'] !== '' && $c['application_secret'] !== '' && $c['consumer_key'] !== '';
    }

    public function search(string $query, array $tlds = []): array
    {
        $query = Str::slug($query);
        $tlds = $tlds === [] ? ['com', 'io', 'dev', 'net', 'org'] : $tlds;

        $results = [];
        foreach ($tlds as $tld) {
            $domain = $query.'.'.strtolower($tld);
            $offer = $this->offersFor($domain)[0] ?? null;
            $pricingMode = (string) ($offer['pricingMode'] ?? '');
            $available = str_starts_with($pricingMode, 'create');
            $premium = str_contains($pricingMode, 'premium');

            $results[] = [
                'domain' => $domain,
                'tld' => strtolower($tld),
                'available' => $available,
                'premium' => $premium,
                'price' => [
                    'amount' => $this->priceFromOffer($offer),
                    'currency' => 'EUR',
                    'years' => 1,
                ],
            ];
        }

        return $results;
    }

    public function checkAvailability(string $domain): array
    {
        $domain = $this->normalize($domain);

        $available = collect($this->offersFor($domain))
            ->contains(fn (array $offer) => str_starts_with((string) ($offer['pricingMode'] ?? ''), 'create'));

        return [
            'domain' => $domain,
            'available' => $available,
        ];
    }

    public function register(string $domain, array $options = []): array
    {
        $domain = $this->normalize($domain);
        $years = max(1, (int) ($options['years'] ?? 1));

        $cartId = $this->createCart();
        $item = $this->post("/order/cart/{$cartId}/domain", [
            'domain' => $domain,
            'duration' => 'P'.$years.'Y',
            'quantity' => 1,
        ]);
        $itemId = (int) ($item['itemId'] ?? 0);

        $this->post("/order/cart/{$cartId}/assign", []);
        $this->applyConfigurations($cartId, $itemId);

        $order = $this->post("/order/cart/{$cartId}/checkout", []);

        return [
            'external_id' => (string) ($order['orderId'] ?? $domain),
            'registered_at' => now()->toIso8601String(),
            'expires_at' => now()->addYears($years)->toIso8601String(),
        ];
    }

    public function renew(string $domain, int $years = 1): array
    {
        $domain = $this->normalize($domain);
        $years = max(1, $years);

        $this->post("/domain/{$domain}/renew", ['duration' => 'P'.$years.'Y']);

        return ['expires_at' => now()->addYears($years)->toIso8601String()];
    }

    public function transfer(string $domain, string $authCode): array
    {
        $domain = $this->normalize($domain);

        $cartId = $this->createCart();
        $item = $this->post("/order/cart/{$cartId}/domain", [
            'domain' => $domain,
            'duration' => 'P1Y',
            'quantity' => 1,
        ]);
        $itemId = (int) ($item['itemId'] ?? 0);

        $this->post("/order/cart/{$cartId}/assign", []);
        $this->applyConfigurations($cartId, $itemId, $authCode);

        $order = $this->post("/order/cart/{$cartId}/checkout", []);

        return [
            'external_id' => (string) ($order['orderId'] ?? $domain),
            'registered_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
        ];
    }

    public function getDetails(string $domain): array
    {
        $domain = $this->normalize($domain);

        $details = $this->get("/domain/{$domain}");
        $nameservers = collect($this->get("/domain/{$domain}/nameServer"))
            ->pluck('host')
            ->map(fn ($host) => trim((string) $host))
            ->all();

        return [
            'domain' => $domain,
            'registrar' => 'OVH',
            'status' => strtolower((string) ($details['status'] ?? 'unknown')),
            'external_id' => $domain,
            'transfer_lock' => ($details['transferLockStatus'] ?? '') === 'locked',
            'privacy_enabled' => (bool) ($details['whoisObfuscated'] ?? false),
            'nameservers' => $nameservers,
            'expires_at' => (string) ($details['expiration'] ?? ''),
        ];
    }

    public function updateContacts(string $domain, array $contacts): array
    {
        $domain = $this->normalize($domain);

        $this->put("/domain/{$domain}/contacts", $contacts);

        return ['contacts' => $contacts];
    }

    public function setPrivacy(string $domain, bool $enabled): array
    {
        $domain = $this->normalize($domain);

        $this->put("/domain/{$domain}", ['whoisObfuscated' => $enabled]);

        return ['privacy_protection' => $enabled];
    }

    public function setTransferLock(string $domain, bool $locked): array
    {
        $domain = $this->normalize($domain);

        $this->put("/domain/{$domain}", ['transferLockStatus' => $locked ? 'locked' : 'unlocked']);

        return ['transfer_lock' => $locked];
    }

    public function setNameservers(string $domain, array $nameservers): array
    {
        $domain = $this->normalize($domain);

        $this->post("/domain/{$domain}/nameServer", [
            'nameServers' => array_map(
                fn (string $ns) => ['host' => $ns],
                array_values($nameservers),
            ),
        ]);

        return ['nameservers' => array_values($nameservers)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function offersFor(string $domain): array
    {
        $cart = $this->post('/order/cart', ['ovhSubsidiary' => $this->config()['subsidiary']]);
        $cartId = (string) ($cart['cartId'] ?? '');

        return $this->get("/order/cart/{$cartId}/domain", ['domain' => $domain]);
    }

    private function createCart(): string
    {
        $cart = $this->post('/order/cart', ['ovhSubsidiary' => $this->config()['subsidiary']]);

        return (string) ($cart['cartId'] ?? '');
    }

    private function applyConfigurations(string $cartId, int $itemId, ?string $authCode = null): void
    {
        $configurations = $this->get("/order/cart/{$cartId}/item/{$itemId}/requiredConfiguration");

        foreach ($configurations as $configuration) {
            $label = (string) ($configuration['label'] ?? '');

            if ($label === 'OWNER_LEGAL_AGE') {
                $this->addConfiguration($cartId, $itemId, $label, true);
            } elseif ($label === 'DNS') {
                $this->addConfiguration($cartId, $itemId, $label, implode(';', config('nexus.domain.default_nameservers')));
            } elseif ($label === 'AUTH_INFO' && $authCode !== null) {
                $this->addConfiguration($cartId, $itemId, $label, $authCode);
            }
        }
    }

    private function addConfiguration(string $cartId, int $itemId, string $label, mixed $value): void
    {
        $this->post("/order/cart/{$cartId}/item/{$itemId}/configuration", [
            'label' => $label,
            'value' => $value,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $offer
     */
    private function priceFromOffer(?array $offer): float
    {
        foreach (($offer['prices'] ?? []) as $price) {
            if (($price['label'] ?? '') === 'TOTAL') {
                return (float) ($price['price']['value'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * @return array{endpoint: string, application_key: string, application_secret: string, consumer_key: string, subsidiary: string}
     */
    private function config(): array
    {
        return [
            'endpoint' => rtrim((string) config('ovh.endpoint'), '/'),
            'application_key' => (string) config('ovh.application_key'),
            'application_secret' => (string) config('ovh.application_secret'),
            'consumer_key' => (string) config('ovh.consumer_key'),
            'subsidiary' => (string) config('ovh.subsidiary', 'FR'),
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new DomainProviderException('OVH provider is not configured (missing API credentials).');
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, [], $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, [], $body);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $this->assertConfigured();

        $c = $this->config();
        $url = $c['endpoint'].$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $bodyString = $body === null ? '' : json_encode($body);
        $timestamp = time();
        $signature = '$1$'.sha1($c['application_secret'].$c['consumer_key'].strtoupper($method).$url.$bodyString.$timestamp);

        $response = Http::withHeaders([
            'X-Ovh-Application' => $c['application_key'],
            'X-Ovh-Consumer' => $c['consumer_key'],
            'X-Ovh-Timestamp' => $timestamp,
            'X-Ovh-Signature' => $signature,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30)->send(strtoupper($method), $url, $body === null ? [] : ['json' => $body]);

        if ($response->failed()) {
            $message = $response->json()['message'] ?? "OVH request failed with HTTP {$response->status()}.";

            if (str_contains(strtolower((string) $message), 'not available') || str_contains(strtolower((string) $message), 'unavailable')) {
                throw new DomainUnavailableException((string) $message);
            }

            throw new DomainProviderException('OVH error: '.$message);
        }

        return $response->json() ?? [];
    }

    private function normalize(string $domain): string
    {
        return strtolower(trim($domain, '. '));
    }
}
