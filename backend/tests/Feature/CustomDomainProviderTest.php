<?php

use App\Support\Domains\DomainProviderException;
use App\Support\Domains\Providers\CustomDomainProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('customregistrar.endpoint', 'https://registrar.example.com/api');
    config()->set('customregistrar.api_key', 'secret-token');
});

function customProvider(): CustomDomainProvider
{
    return new CustomDomainProvider;
}

it('reports configured state from the endpoint', function () {
    expect(customProvider()->isConfigured())->toBeTrue();

    config()->set('customregistrar.endpoint', '');

    expect(customProvider()->isConfigured())->toBeFalse();
});

it('rejects calls without an endpoint', function () {
    config()->set('customregistrar.endpoint', '');

    customProvider()->checkAvailability('acme.com');
})->throws(DomainProviderException::class);

it('checks availability', function () {
    Http::fake(fn () => Http::response(['data' => ['domain' => 'acme.com', 'available' => true]]));

    expect(customProvider()->checkAvailability('acme.com')['available'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://registrar.example.com/api'
            && $request['command'] === 'check'
            && $request['domain'] === 'acme.com';
    });
});

it('registers a domain', function () {
    Http::fake(fn () => Http::response([
        'data' => ['external_id' => 'ext-1', 'registered_at' => '2026-08-16T00:00:00Z', 'expires_at' => '2027-08-16T00:00:00Z'],
    ]));

    $result = customProvider()->register('acme.com', ['years' => 1]);

    expect($result['external_id'])->toBe('ext-1');

    Http::assertSent(fn ($request) => $request['command'] === 'register'
        && $request['domain'] === 'acme.com'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('searches availability', function () {
    Http::fake(fn () => Http::response([
        'data' => [
            ['domain' => 'acme.com', 'tld' => 'com', 'available' => true, 'premium' => false, 'price' => ['amount' => 10.0, 'currency' => 'USD', 'years' => 1]],
        ],
    ]));

    expect(customProvider()->search('acme', ['com']))->toHaveCount(1);
});

it('throws a provider exception on an error response', function () {
    Http::fake(fn () => Http::response(['error' => 'Invalid API key'], 401));

    customProvider()->checkAvailability('acme.com');
})->throws(DomainProviderException::class);
