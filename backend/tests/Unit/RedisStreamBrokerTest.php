<?php

use App\Support\Streams\RedisStreamBroker;
use Predis\Client;

it('publishes a namespaced, JSON-encoded event', function () {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('publish')
        ->once()
        ->with('omnex:notifications:u1', '{"id":1,"title":"Hi"}')
        ->andReturn(1);

    $broker = new RedisStreamBroker('omnex:', 'default', fn () => $client);

    $broker->publish('notifications:u1', ['id' => 1, 'title' => 'Hi']);
});

it('swallows publish failures so the business operation is unaffected', function () {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('publish')
        ->once()
        ->andThrow(new RuntimeException('redis down'));

    $broker = new RedisStreamBroker('omnex:', 'default', fn () => $client);

    $broker->publish('notifications:u1', ['id' => 1]);

    expect(true)->toBeTrue();
});

it('does nothing for a non-positive listen timeout', function () {
    $factory = fn () => throw new RuntimeException('should never connect');

    $broker = new RedisStreamBroker('omnex:', 'default', $factory);

    $broker->listen('activity:org-1', fn () => null, 0);

    expect(true)->toBeTrue();
});
