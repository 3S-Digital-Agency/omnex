<?php

use App\Support\Domains\DomainProviderException;
use App\Support\Domains\Providers\CloudflareDnsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('cloudflare.endpoint', 'https://api.cloudflare.com/client/v4');
    config()->set('cloudflare.api_token', 'test-token-123');
    config()->set('cloudflare.account_id', 'acct-1');
    config()->set('cloudflare.default_proxied', false);
});

/** Path (no query, endpoint prefix stripped) of a fake HTTP request. */
function cloudflarePath(Request $request): string
{
    $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

    return preg_replace('#^/client/v4#', '', $path) ?: $path;
}

it('rejects calls before the API token is set', function () {
    config()->set('cloudflare.api_token', '');

    (new CloudflareDnsProvider)->createZone('example.com');
})->throws(DomainProviderException::class, 'CLOUDFLARE_API_TOKEN');

it('creates a zone with the account id', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
        }
        if ($path === '/zones' && $request->method() === 'POST') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'zone-42']]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    expect((new CloudflareDnsProvider)->createZone('example.com'))->toBe(['external_id' => 'zone-42']);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && cloudflarePath($request) === '/zones'
        && $request['name'] === 'example.com'
        && $request->hasHeader('Authorization', 'Bearer test-token-123'));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['name'] === 'example.com'
        && $request['account'] === ['id' => 'acct-1']);
});

it('reuses an existing zone instead of creating a duplicate', function () {
    Http::fake(function (Request $request) {
        if (cloudflarePath($request) === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-9', 'name' => 'example.com']]]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    expect((new CloudflareDnsProvider)->createZone('example.com'))->toBe(['external_id' => 'zone-9']);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('deletes a zone by its id', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42' && $request->method() === 'DELETE') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    expect((new CloudflareDnsProvider)->deleteZone('example.com'))->toBe([]);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && cloudflarePath($request) === '/zones/zone-42');
});

it('creates a proxied A record', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42/dns_records' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
        }
        if ($path === '/zones/zone-42/dns_records' && $request->method() === 'POST') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'record-7']]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    $record = ['name' => 'app.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 300, 'priority' => null, 'proxied' => true];

    expect((new CloudflareDnsProvider)->createRecord('example.com', $record))->toBe(['external_id' => 'record-7']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && cloudflarePath($request) === '/zones/zone-42/dns_records'
        && $request['type'] === 'A'
        && $request['name'] === 'app.example.com'
        && $request['content'] === '1.2.3.4'
        && $request['proxied'] === true);
});

it('keeps MX records unproxied with their priority', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42/dns_records' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    (new CloudflareDnsProvider)->createRecord('example.com', [
        'name' => 'example.com', 'type' => 'MX', 'content' => 'mx1.example.com',
        'ttl' => 300, 'priority' => 10, 'proxied' => true,
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['priority'] === 10
        && ! isset($request['proxied']));
});

it('updates an existing record idempotently', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42/dns_records' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'record-7', 'content' => '1.2.3.4']]]);
        }
        if ($path === '/zones/zone-42/dns_records/record-7' && $request->method() === 'PATCH') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'record-7']]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    (new CloudflareDnsProvider)->updateRecord('example.com', [
        'name' => 'app.example.com', 'type' => 'A', 'content' => '1.2.3.4',
        'ttl' => 300, 'priority' => null, 'proxied' => false,
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && cloudflarePath($request) === '/zones/zone-42/dns_records/record-7');
});

it('deletes a record only when it exists', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42/dns_records' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'record-7', 'content' => '1.2.3.4']]]);
        }
        if ($path === '/zones/zone-42/dns_records/record-7' && $request->method() === 'DELETE') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    (new CloudflareDnsProvider)->deleteRecord('example.com', [
        'name' => 'app.example.com', 'type' => 'A', 'content' => '1.2.3.4',
        'ttl' => 300, 'priority' => null, 'proxied' => false,
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && cloudflarePath($request) === '/zones/zone-42/dns_records/record-7');
});

it('enables DNSSEC and returns the DS record', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42/dnssec' && $request->method() === 'PATCH') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [
                'status' => 'active',
                'key_tag' => 2371,
                'algorithm' => 13,
                'digest_type' => 2,
                'digest' => 'abcdef0123456789',
            ]]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    $ds = (new CloudflareDnsProvider)->enableDnssec('example.com');

    expect($ds)->toBe([[
        'key_tag' => 2371,
        'algorithm' => 13,
        'digest_type' => 2,
        'digest' => 'ABCDEF0123456789',
    ]]);
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && json_decode($request->body(), true)['status'] === 'active');
});

it('parses the space-separated ds string Cloudflare sometimes returns', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42/dnssec' && $request->method() === 'PATCH') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['status' => 'active', 'ds' => '2371 13 2 ABCDEF']]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    $ds = (new CloudflareDnsProvider)->enableDnssec('example.com');

    expect($ds[0]['key_tag'])->toBe(2371);
    expect($ds[0]['digest'])->toBe('ABCDEF');
});

it('disables DNSSEC', function () {
    Http::fake(function (Request $request) {
        $path = cloudflarePath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42/dnssec' && $request->method() === 'PATCH') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['status' => 'disabled']]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    expect((new CloudflareDnsProvider)->disableDnssec('example.com'))->toBe([]);
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && json_decode($request->body(), true)['status'] === 'disabled');
});

it('raises when the zone does not exist on Cloudflare', function () {
    Http::fake(fn () => Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]));

    (new CloudflareDnsProvider)->createRecord('nosuch.example.com', [
        'name' => 'x.nosuch.example.com', 'type' => 'A', 'content' => '1.2.3.4',
        'ttl' => 300, 'priority' => null, 'proxied' => false,
    ]);
})->throws(DomainProviderException::class, 'no zone');

it('raises on a rejected token', function () {
    Http::fake(fn () => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Invalid token']]], 401));

    (new CloudflareDnsProvider)->deleteZone('example.com');
})->throws(DomainProviderException::class, '401/403');

it('raises on Cloudflare API errors with the returned message', function () {
    Http::fake(function (Request $request) {
        if (cloudflarePath($request) === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
        }

        return Http::response(['success' => false, 'errors' => [['code' => 81057, 'message' => 'Zone already exists']]], 400);
    });

    (new CloudflareDnsProvider)->createZone('example.com');
})->throws(DomainProviderException::class, '81057 Zone already exists');
