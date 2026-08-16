<?php

use App\Support\Cloud\Providers\CustomServerProvider;
use App\Support\Cloud\Providers\DigitalOceanServerProvider;
use App\Support\Cloud\Providers\HetznerServerProvider;
use App\Support\Cloud\Providers\SandboxServerProvider;
use App\Support\Cloud\ServerOperationFailedException;
use Illuminate\Support\Facades\Http;

it('provisions deterministic servers in the sandbox', function () {
    $provider = new SandboxServerProvider;

    expect($provider->name())->toBe('sandbox');
    expect($provider->label())->toBe('Sandbox');
    expect($provider->isConfigured())->toBeTrue();

    $result = $provider->provision('web', 'fsn1', 'cpx11', 'ubuntu-24.04', '');

    expect($result['provider_server_id'])->toStartWith('sbox-srv-web-');
    expect($result['ipv4'])->toMatch('/^\d+\.\d+\.\d+\.\d+$/');
    expect($result['status'])->toBe('running');

    // Deterministic: same inputs, same addresses.
    $again = $provider->provision('web', 'fsn1', 'cpx11', 'ubuntu-24.04', '');
    expect($again['ipv4'])->toBe($result['ipv4']);
});

it('fails sandbox provisioning deterministically for the name "fail"', function () {
    $provider = new SandboxServerProvider;

    expect(fn () => $provider->provision('fail', 'fsn1', 'cpx11', 'ubuntu-24.04', ''))
        ->toThrow(ServerOperationFailedException::class);
});

it('fails sandbox operations on servers whose id contains "fail"', function () {
    $provider = new SandboxServerProvider;

    expect(fn () => $provider->start('sbox-srv-fail-box-abc12345'))
        ->toThrow(ServerOperationFailedException::class);
    expect(fn () => $provider->rebuild('sbox-srv-fail-box-abc12345', 'debian-12'))
        ->toThrow(ServerOperationFailedException::class);
});

it('samples deterministic synthetic metrics in the sandbox', function () {
    $provider = new SandboxServerProvider;

    $metrics = $provider->metrics('sbox-srv-web-01-a1b2c3d4');

    expect($metrics)->toHaveKeys(['cpu', 'memory_used', 'memory_total', 'disk_used', 'disk_total'])
        ->and($metrics['cpu'])->toBeBetween(5, 95)
        ->and($metrics['memory_used'])->toBeLessThanOrEqual($metrics['memory_total'])
        ->and($metrics['disk_used'])->toBeLessThanOrEqual($metrics['disk_total'])
        ->and($metrics['memory_total'])->toBe(4 * 1024 * 1024 * 1024)
        ->and($metrics['disk_total'])->toBe(80 * 1024 * 1024 * 1024);

    // Deterministic within the same 5-second time bucket.
    expect($provider->metrics('sbox-srv-web-01-a1b2c3d4'))->toBe($metrics);
});

it('reports metrics through the custom gateway', function () {
    config()->set('customcloud.endpoint', 'https://compute.example.com');
    config()->set('customcloud.api_key', 'key');

    Http::fake([
        'compute.example.com' => Http::response([
            'data' => ['cpu' => 42, 'memory_used' => 1000, 'memory_total' => 4096, 'disk_used' => 5000, 'disk_total' => 81920],
        ], 200),
    ]);

    $provider = new CustomServerProvider;

    expect($provider->metrics('custom-1'))->toBe([
        'cpu' => 42, 'memory_used' => 1000, 'memory_total' => 4096, 'disk_used' => 5000, 'disk_total' => 81920,
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://compute.example.com'
        && $request['command'] === 'metrics'
        && $request->hasHeader('Authorization', 'Bearer key'));
});

it('verifies the sandbox without any external call', function () {
    $provider = new SandboxServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => true]);
});

it('verifies a hetzner token with a read-only call', function () {
    $provider = new HetznerServerProvider;

    // No token → fails without any HTTP call.
    expect($provider->verify())->toMatchArray(['ok' => false]);
    Http::assertNothingSent();
});

it('reports a valid hetzner token', function () {
    config()->set('hetzner.token', 'hz-token');

    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response(['servers' => []], 200),
    ]);

    $provider = new HetznerServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => true]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.hetzner.cloud/v1/servers')
        && $request->hasHeader('Authorization', 'Bearer hz-token'));
});

it('reports an invalid hetzner token', function () {
    config()->set('hetzner.token', 'hz-token');

    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response(['error' => ['message' => 'unauthorized']], 401),
    ]);

    $provider = new HetznerServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => false]);
});

it('verifies a digitalocean token with a read-only call', function () {
    $provider = new DigitalOceanServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => false]);
});

it('reports a valid digitalocean token', function () {
    config()->set('digitalocean.token', 'do-token');

    Http::fake([
        'api.digitalocean.com/v2/account' => Http::response(['account' => []], 200),
    ]);

    $provider = new DigitalOceanServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => true]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.digitalocean.com/v2/account'
        && $request->hasHeader('Authorization', 'Bearer do-token'));
});

it('reports an invalid digitalocean token', function () {
    config()->set('digitalocean.token', 'do-token');

    Http::fake([
        'api.digitalocean.com/v2/account' => Http::response(['message' => 'unauthorized'], 401),
    ]);

    $provider = new DigitalOceanServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => false]);
});

it('verifies the custom gateway with a ping command', function () {
    $provider = new CustomServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => false]);
});

it('reports a reachable custom gateway', function () {
    config()->set('customcloud.endpoint', 'https://compute.example.com');
    config()->set('customcloud.api_key', 'key');

    Http::fake([
        'compute.example.com' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $provider = new CustomServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => true]);

    Http::assertSent(fn ($request) => $request['command'] === 'ping');
});

it('reports an unreachable custom gateway', function () {
    config()->set('customcloud.endpoint', 'https://compute.example.com');
    config()->set('customcloud.api_key', 'key');

    Http::fake([
        'compute.example.com' => Http::response(['error' => 'gateway down'], 503),
    ]);

    $provider = new CustomServerProvider;

    expect($provider->verify())->toMatchArray(['ok' => false]);
});

it('reports hetzner configuration from the token', function () {
    $provider = new HetznerServerProvider;

    expect($provider->isConfigured())->toBeFalse();

    config()->set('hetzner.token', 'hz-token');

    expect($provider->isConfigured())->toBeTrue();
});

it('provisions a hetzner server through the API', function () {
    config()->set('hetzner.token', 'hz-token');

    Http::fake([
        'api.hetzner.cloud/v1/servers' => Http::response([
            'server' => [
                'id' => 123,
                'status' => 'running',
                'public_net' => [
                    'ipv4' => ['ip' => '1.2.3.4'],
                    'ipv6' => ['ip' => '2a01:4f8::1'],
                ],
            ],
        ], 201),
    ]);

    $provider = new HetznerServerProvider;
    $result = $provider->provision('web', 'fsn1', 'cpx11', 'ubuntu-24.04', '');

    expect($result['provider_server_id'])->toBe('123');
    expect($result['ipv4'])->toBe('1.2.3.4');
    expect($result['ipv6'])->toBe('2a01:4f8::1');
    expect($result['status'])->toBe('running');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && $request['name'] === 'web'
        && $request['location'] === 'fsn1'
        && $request->hasHeader('Authorization', 'Bearer hz-token'));
});

it('runs hetzner power actions and maps upstream errors to operation failures', function () {
    config()->set('hetzner.token', 'hz-token');

    Http::fake([
        'api.hetzner.cloud/v1/servers/1/actions/*' => Http::response(['action' => ['status' => 'success']], 201),
        'api.hetzner.cloud/v1/servers/2/actions/*' => Http::response(['error' => ['message' => 'server is locked']], 409),
    ]);

    $provider = new HetznerServerProvider;

    $provider->start('1');
    $provider->reboot('1');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/start'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/reboot'));

    expect(fn () => $provider->stop('2'))->toThrow(ServerOperationFailedException::class, 'server is locked');
});

it('provisions a digitalocean droplet through the API', function () {
    config()->set('digitalocean.token', 'do-token');

    Http::fake([
        'api.digitalocean.com/v2/droplets' => Http::response([
            'droplet' => [
                'id' => 456,
                'status' => 'active',
                'networks' => [
                    'v4' => [['ip_address' => '10.0.0.1']],
                    'v6' => [],
                ],
            ],
        ], 202),
    ]);

    $provider = new DigitalOceanServerProvider;
    $result = $provider->provision('web', 'nyc1', 's-1vcpu-1gb', 'ubuntu-24-04-x64', '');

    expect($result['provider_server_id'])->toBe('456');
    expect($result['ipv4'])->toBe('10.0.0.1');
    expect($result['status'])->toBe('running');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.digitalocean.com/v2/droplets'
        && $request['size'] === 's-1vcpu-1gb'
        && $request->hasHeader('Authorization', 'Bearer do-token'));
});

it('creates, lists and deletes snapshots in the sandbox', function () {
    $provider = new SandboxServerProvider;

    $created = $provider->snapshot('sbox-srv-web-01', 'snapshot-2026-01-01');

    expect($created['provider_snapshot_id'])->toStartWith('sbox-snap-');
    expect($created['status'])->toBe('available');

    $list = $provider->listSnapshots('sbox-srv-web-01');

    expect($list)->toHaveCount(1)
        ->and($list[0]['label'])->toBe('snapshot-2026-01-01')
        ->and($list[0]['created_at'])->not->toBeNull();

    $provider->deleteSnapshot('sbox-srv-web-01', $created['provider_snapshot_id']);

    expect($provider->listSnapshots('sbox-srv-web-01'))->toBe([]);
});

it('creates and lists hetzner snapshots through the API', function () {
    config()->set('hetzner.token', 'hz-token');

    Http::fake([
        'api.hetzner.cloud/v1/servers/1/actions/create_image' => Http::response([
            'image' => ['id' => 777, 'status' => 'available'],
        ], 201),
        'api.hetzner.cloud/v1/images*' => Http::response([
            'images' => [
                ['id' => 777, 'type' => 'snapshot', 'description' => 'backup-1', 'status' => 'available', 'created' => '2026-01-01T00:00:00+00:00'],
            ],
        ], 200),
    ]);

    $provider = new HetznerServerProvider;

    $result = $provider->snapshot('1', 'backup-1');

    expect($result['provider_snapshot_id'])->toBe('777')
        ->and($result['status'])->toBe('available');

    $list = $provider->listSnapshots('1');

    expect($list)->toHaveCount(1)
        ->and($list[0]['label'])->toBe('backup-1')
        ->and($list[0]['created_at'])->not->toBeNull();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/servers/1/actions/create_image')
        && $request['type'] === 'snapshot'
        && $request['description'] === 'backup-1');
});

it('deletes hetzner snapshots idempotently on 404', function () {
    config()->set('hetzner.token', 'hz-token');

    Http::fake([
        'api.hetzner.cloud/v1/images/777' => Http::response([], 404),
    ]);

    $provider = new HetznerServerProvider;

    $provider->deleteSnapshot('1', '777');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/images/777'
        && $request->method() === 'DELETE');
});

it('creates and lists digitalocean snapshots through the API', function () {
    config()->set('digitalocean.token', 'do-token');

    Http::fake([
        'api.digitalocean.com/v2/droplets/456/actions' => Http::response([
            'action' => ['type' => 'snapshot', 'status' => 'in-progress'],
        ], 201),
        'api.digitalocean.com/v2/droplets/456/snapshots' => Http::response([
            'snapshots' => [
                ['id' => 888, 'name' => 'backup-1', 'status' => 'available', 'created_at' => '2026-01-01T00:00:00Z'],
            ],
        ], 200),
    ]);

    $provider = new DigitalOceanServerProvider;

    $result = $provider->snapshot('456', 'backup-1');

    expect($result['status'])->toBe('creating');

    $list = $provider->listSnapshots('456');

    expect($list)->toHaveCount(1)
        ->and($list[0]['provider_snapshot_id'])->toBe('888')
        ->and($list[0]['label'])->toBe('backup-1')
        ->and($list[0]['status'])->toBe('available');
});

it('forwards snapshot commands through the custom gateway', function () {
    config()->set('customcloud.endpoint', 'https://compute.example.com');
    config()->set('customcloud.api_key', 'key');

    Http::fake([
        'compute.example.com' => Http::response([
            'data' => ['provider_snapshot_id' => 'cus-snap-1', 'status' => 'available'],
        ], 200),
    ]);

    $provider = new CustomServerProvider;

    $result = $provider->snapshot('custom-1', 'backup-1');

    expect($result['provider_snapshot_id'])->toBe('cus-snap-1');

    Http::assertSent(fn ($request) => $request['command'] === 'snapshot'
        && $request['label'] === 'backup-1'
        && $request['provider_server_id'] === 'custom-1');
});

it('maps digitalocean droplet actions and errors', function () {
    config()->set('digitalocean.token', 'do-token');

    Http::fake([
        'api.digitalocean.com/v2/droplets/456/actions' => Http::response(['action' => ['status' => 'in-progress']], 201),
        'api.digitalocean.com/v2/droplets/999/actions' => Http::response(['message' => 'not found'], 404),
    ]);

    $provider = new DigitalOceanServerProvider;

    $provider->stop('456');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.digitalocean.com/v2/droplets/456/actions'
        && $request['type'] === 'shutdown');

    expect(fn () => $provider->reboot('999'))->toThrow(ServerOperationFailedException::class, 'not found');
});
