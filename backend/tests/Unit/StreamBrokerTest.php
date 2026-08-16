<?php

use App\Support\Streams\InProcessStreamBroker;

it('buffers and delivers events per channel', function () {
    $broker = new InProcessStreamBroker;

    $broker->publish('a', ['id' => 1]);
    $broker->publish('a', ['id' => 2]);
    $broker->publish('b', ['id' => 3]);

    $received = [];
    $broker->listen('a', function (array $event) use (&$received) {
        $received[] = $event;
    }, 0);

    expect($received)->toBe([
        ['id' => 1],
        ['id' => 2],
    ]);

    // Delivery is destructive: a second pass sees nothing.
    $again = [];
    $broker->listen('a', function (array $event) use (&$again) {
        $again[] = $event;
    }, 0);

    expect($again)->toBe([]);

    $b = [];
    $broker->listen('b', function (array $event) use (&$b) {
        $b[] = $event;
    }, 0);

    expect($b)->toBe([['id' => 3]]);
});

it('returns promptly on a zero timeout without blocking', function () {
    $broker = new InProcessStreamBroker;

    $start = microtime(true);
    $broker->listen('empty', fn () => null, 0);

    expect(microtime(true) - $start)->toBeLessThan(0.5);
});
