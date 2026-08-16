<?php

namespace App\Support\Streams;

use Closure;
use Illuminate\Support\Facades\Log;
use Predis\Client;
use Predis\PredisException;
use Throwable;

/**
 * Redis pub/sub broker. Events published by one PHP process are delivered to
 * every process currently listening on the channel — the requirement for
 * horizontally-scaled deployments.
 *
 * Redis pub/sub is fire-and-forget: there is no replay for subscribers that
 * were not connected when an event was published. The REST endpoints remain
 * the source of truth, so clients that miss a real-time frame catch up on the
 * next poll (the frontend already does this).
 */
final class RedisStreamBroker implements StreamBroker
{
    /**
     * @param  string  $prefix  channel namespace, e.g. "omnex:"
     * @param  (Closure(?float): Client)|null  $clientFactory  test seam; defaults to a real Predis client
     */
    public function __construct(
        private readonly string $prefix,
        private readonly string $connectionName,
        private readonly ?Closure $clientFactory = null,
    ) {}

    public function publish(string $channel, array $event): void
    {
        try {
            $this->client()->publish(
                $this->full($channel),
                json_encode($event, JSON_THROW_ON_ERROR),
            );
        } catch (Throwable $e) {
            // Real-time delivery must never fail the business operation that
            // produced the event (domain registration, deployment, ...).
            Log::warning('Stream publish failed', [
                'channel' => $this->full($channel),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function listen(string $channel, callable $onEvent, int $timeoutSeconds): void
    {
        if ($timeoutSeconds <= 0) {
            return;
        }

        // A dedicated connection with a read timeout turns the blocking
        // subscribe into a bounded listen window.
        $client = $this->client((float) $timeoutSeconds);

        try {
            $loop = $client->pubSubLoop();
            $loop->subscribe($this->full($channel));

            foreach ($loop as $message) {
                if (($message->kind ?? null) !== 'message') {
                    continue;
                }

                $decoded = json_decode((string) $message->payload, true);
                $onEvent(is_array($decoded) ? $decoded : ['message' => $message->payload]);
            }
        } catch (PredisException $e) {
            // A read timeout (or a dropped connection) simply ends this listen
            // window; the SSE loop reconnects on the next heartbeat.
        } finally {
            $client->disconnect();
        }
    }

    private function full(string $channel): string
    {
        return $this->prefix.$channel;
    }

    private function client(?float $timeoutSeconds = null): Client
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($timeoutSeconds);
        }

        return new Client($this->parameters($timeoutSeconds));
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(?float $timeoutSeconds = null): array
    {
        $connection = (array) config("database.redis.{$this->connectionName}", []);

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

        if ($timeoutSeconds !== null) {
            $parameters['read_write_timeout'] = $timeoutSeconds;
        }

        return $parameters;
    }
}
