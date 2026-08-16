<?php

namespace App\Support\Streams;

/**
 * In-process broker. Buffers events per channel in memory and delivers them to
 * listeners on the next listen() pass.
 *
 * This is the default driver for local development and the test suite. It is
 * single-process only: an event published by one PHP worker is invisible to a
 * subscriber in another. Use the Redis driver for multi-process deployments.
 */
final class InProcessStreamBroker implements StreamBroker
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $queues = [];

    public function publish(string $channel, array $event): void
    {
        $this->queues[$channel][] = $event;
    }

    public function listen(string $channel, callable $onEvent, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + max(0, $timeoutSeconds);

        while (true) {
            $events = $this->queues[$channel] ?? [];

            if ($events !== []) {
                $this->queues[$channel] = [];

                foreach ($events as $event) {
                    $onEvent($event);
                }
            }

            if (microtime(true) >= $deadline) {
                return;
            }

            // Poll cheaply so events published by other code in this process
            // (e.g. a request that ran during the window) are picked up.
            usleep(50_000);
        }
    }
}
