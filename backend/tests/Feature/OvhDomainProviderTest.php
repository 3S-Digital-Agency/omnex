<?php

use App\Support\Domains\DomainProviderException;
use App\Support\Domains\Providers\OvhDomainProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ovh.endpoint', 'https://eu.api.ovh.com/1.0');
    config()->set('ovh.application_key', 'ak-test');
    config()->set('ovh.application_secret', 'as-test');
    config()->set('ovh.consumer_key', 'ck-test');
    config()->set('ovh.subsidiary', 'FR');
});

function ovhProvider(): OvhDomainProvider
{
    return new OvhDomainProvider;
}

function ovhFake(): void
{
    Http::fake(function ($request) {
        $method = strtoupper($request->method());
        $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
        $path = preg_replace('#^/1\.0#', '', $path);

        if ($method === 'POST' && $path === '/order/cart') {
            return Http::response(['cartId' => 'cart-123']);
        }

        if ($method === 'GET' && preg_match('#^/order/cart/cart-123/domain$#', $path)) {
            return Http::response([[
                'action' => 'create',
                'offerId' => 'com-create',
                'pricingMode' => 'create-default',
                'duration' => ['P1Y'],
                'prices' => [
                    ['label' => 'TOTAL', 'price' => ['currencyCode' => 'EUR', 'value' => 9.99]],
                ],
            ]]);
        }

        if ($method === 'POST' && preg_match('#^/order/cart/cart-123/domain$#', $path)) {
            return Http::response(['cartId' => 'cart-123', 'itemId' => 42]);
        }

        if ($method === 'POST' && preg_match('#^/order/cart/cart-123/assign$#', $path)) {
            return Http::response(null);
        }

        if ($method === 'GET' && preg_match('#/requiredConfiguration$#', $path)) {
            return Http::response([
                ['label' => 'OWNER_LEGAL_AGE', 'type' => 'bool'],
                ['label' => 'DNS', 'type' => 'String'],
            ]);
        }

        if ($method === 'POST' && preg_match('#/configuration$#', $path)) {
            return Http::response(null);
        }

        if ($method === 'POST' && preg_match('#/checkout$#', $path)) {
            return Http::response(['orderId' => 987654, 'url' => 'https://www.ovh.com/cgi-bin/order/complete.cgi']);
        }

        if ($method === 'GET' && preg_match('#^/domain/[^/]+$#', $path)) {
            return Http::response([
                'status' => 'ok',
                'transferLockStatus' => 'locked',
                'whoisObfuscated' => true,
                'expiration' => '2027-08-16',
            ]);
        }

        if ($method === 'GET' && preg_match('#/nameServer$#', $path)) {
            return Http::response([['host' => 'ns1.example.com'], ['host' => 'ns2.example.com']]);
        }

        if ($method === 'POST' && preg_match('#/nameServer$#', $path)) {
            return Http::response(null);
        }

        if ($method === 'PUT' && preg_match('#^/domain/[^/]+$#', $path)) {
            return Http::response(null);
        }

        if ($method === 'PUT' && preg_match('#/contacts$#', $path)) {
            return Http::response(null);
        }

        if ($method === 'POST' && preg_match('#/renew$#', $path)) {
            return Http::response(['orderId' => 555]);
        }

        return Http::response(['message' => 'Not found'], 404);
    });
}

it('reports configured state from credentials', function () {
    expect(ovhProvider()->isConfigured())->toBeTrue();

    config()->set('ovh.consumer_key', '');

    expect(ovhProvider()->isConfigured())->toBeFalse();
});

it('rejects calls before credentials are set', function () {
    config()->set('ovh.consumer_key', '');

    ovhProvider()->checkAvailability('acme.com');
})->throws(DomainProviderException::class);

it('signs requests with the OVH signature', function () {
    ovhFake();

    ovhProvider()->checkAvailability('acme.com');

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Ovh-Application', 'ak-test')
            && $request->hasHeader('X-Ovh-Consumer', 'ck-test')
            && str_starts_with($request->header('X-Ovh-Signature')[0] ?? '', '$1$')
            && (int) ($request->header('X-Ovh-Timestamp')[0] ?? 0) > 0;
    });
});

it('checks availability from the cart offers', function () {
    ovhFake();

    expect(ovhProvider()->checkAvailability('acme.com')['available'])->toBeTrue();
});

it('searches availability across tlds with pricing', function () {
    ovhFake();

    $results = ovhProvider()->search('acme', ['com']);

    expect($results)->toHaveCount(1);
    expect($results[0])->toMatchArray([
        'domain' => 'acme.com',
        'tld' => 'com',
        'available' => true,
        'premium' => false,
    ]);
    expect($results[0]['price']['amount'])->toBe(9.99);
    expect($results[0]['price']['currency'])->toBe('EUR');
});

it('registers a domain through the cart flow', function () {
    ovhFake();

    $result = ovhProvider()->register('acme.com', ['years' => 1]);

    expect($result['external_id'])->toBe('987654');
    expect($result['expires_at'])->not->toBeNull();
});

it('renews a domain', function () {
    ovhFake();

    expect(ovhProvider()->renew('acme.com', 2)['expires_at'])->not->toBeNull();
});

it('transfers a domain with an auth code', function () {
    ovhFake();

    expect(ovhProvider()->transfer('acme.com', 'AUTH-CODE')['external_id'])->toBe('987654');
});

it('parses domain details and nameservers', function () {
    ovhFake();

    $details = ovhProvider()->getDetails('acme.com');

    expect($details['status'])->toBe('ok');
    expect($details['transfer_lock'])->toBeTrue();
    expect($details['privacy_enabled'])->toBeTrue();
    expect($details['expires_at'])->toBe('2027-08-16');
    expect($details['nameservers'])->toBe(['ns1.example.com', 'ns2.example.com']);
});

it('updates privacy, lock and nameservers', function () {
    ovhFake();

    expect(ovhProvider()->setPrivacy('acme.com', false)['privacy_protection'])->toBeFalse();
    expect(ovhProvider()->setTransferLock('acme.com', false)['transfer_lock'])->toBeFalse();
    expect(ovhProvider()->setNameservers('acme.com', ['ns1.example.com', 'ns2.example.com'])['nameservers'])
        ->toBe(['ns1.example.com', 'ns2.example.com']);
});

it('throws a provider exception on an OVH API error', function () {
    Http::fake(fn () => Http::response(['message' => 'This resource does not exist'], 404));

    ovhProvider()->checkAvailability('acme.com');
})->throws(DomainProviderException::class);
