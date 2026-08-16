<?php

use App\Support\Streams\RedisStreamBroker;
use Predis\Client;

/**
 * Exercises the Redis pub/sub broker against a real Redis instance. Skipped
 * when Redis is not reachable so the suite stays green on machines without it.
 *
 * The publish -> listen delivery path is inherently cross-process; within a
 * single process the listen() call blocks the socket, so this test verifies
 * publish() and the graceful listen() timeout. Full end-to-end delivery is
 * covered by pointing the SSE endpoints at Redis (OMNEX_STREAM_DRIVER=redis)
 * and running more than one worker.
 */
it('publishes and times out gracefully against a live Redis', function () {
    $client = new Client(testRedisParameters());

    try {
        $client->ping();
    } catch (Throwable) {
        $client->disconnect();
        $this->markTestSkipped('Redis is not reachable.');
    }
    $client->disconnect();

    $broker = new RedisStreamBroker('omnex:', 'default');

    // publish() swallows transport failures but must not error here.
    expect(fn () => $broker->publish('notifications:integration', ['id' => 1]))
        ->not->toThrow(Throwable::class);

    // listen() with nothing published must return promptly, without error.
    $start = microtime(true);
    $received = [];
    $broker->listen('activity:integration', function (array $event) use (&$received) {
        $received[] = $event;
    }, 1);

    expect($received)->toBe([])
        ->and(microtime(true) - $start)->toBeLessThan(5.0);
});

/**
 * @return array<string, mixed>
 */
function testRedisParameters(): array
{
    $connection = (array) config('database.redis.default', []);

    $parameters = [
        'scheme' => 'tcp',
        'host' => $connection['host'] ?? '127.0.0.1',
        'port' => (int) ($connection['port'] ?? 6379),
    ];

    foreach (['username', 'password'] as $key) {
        if (! empty($connection[$key])) {
            $parameters[$key] = $connection[$key];
        }
    }

    if (isset($connection['database'])) {
        $parameters['database'] = (int) $connection['database'];
    }

    return $parameters;
}
