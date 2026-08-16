<?php

use App\Support\Domains\ZoneFileParser;

it('parses a zone file with directives and comments', function () {
    $zone = implode("\n", [
        '$ORIGIN example.com.',
        '$TTL 3600',
        '; comment line',
        '@   IN  A   192.0.2.1',
        'www 300 IN CNAME @',
        '@   IN  MX  10 mail.example.com.',
    ]);

    $records = ZoneFileParser::parse($zone, 'example.com');

    expect($records)->toHaveCount(3);
    expect($records[0])->toMatchArray(['name' => '@', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600]);
    expect($records[1])->toMatchArray(['name' => 'www', 'type' => 'CNAME', 'content' => '@', 'ttl' => 300]);
    expect($records[2])->toMatchArray(['name' => '@', 'type' => 'MX', 'content' => 'mail.example.com', 'priority' => 10]);
});

it('round-trips records through export', function () {
    $records = [
        ['name' => '@', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'priority' => null],
        ['name' => 'www', 'type' => 'CNAME', 'content' => '@', 'ttl' => 300, 'priority' => null],
    ];

    $exported = ZoneFileParser::export('example.com', $records);

    expect($exported)->toContain('$ORIGIN example.com.');
    expect($exported)->toContain('@');

    $parsed = ZoneFileParser::parse($exported, 'example.com');
    expect($parsed)->toHaveCount(2);
    expect(collect($parsed)->pluck('type')->all())->toBe(['A', 'CNAME']);
});
