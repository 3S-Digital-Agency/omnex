<?php

namespace App\Support\Streams;

/**
 * Transport-agnostic event stream used for real-time (SSE) delivery.
 *
 * Events are addressed by a logical channel (e.g. "notifications:{userId}",
 * "activity:{organizationId}"). Producers call publish(); the SSE endpoints
 * call listen() and receive a callback for every event that arrives during
 * the listen window.
 *
 * The in-process implementation buffers events in memory (single process,
 * local dev/test). The Redis implementation uses pub/sub so events published
 * by one PHP process reach subscribers connected to another — the requirement
 * for horizontally-scaled deployments.
 */
interface StreamBroker
{
    /**
     * Broadcast an event to every current subscriber of the channel.
     *
     * @param  array<string, mixed>  $event
     */
    public function publish(string $channel, array $event): void;

    /**
     * Block for up to $timeoutSeconds, invoking $onEvent for each event that
     * arrives on the channel. Returns when the window elapses.
     *
     * A timeout of zero performs a single non-blocking pass (used by tests and
     * the final drain before an SSE connection closes).
     *
     * @param  callable(array<string, mixed>): void  $onEvent
     */
    public function listen(string $channel, callable $onEvent, int $timeoutSeconds): void;
}
