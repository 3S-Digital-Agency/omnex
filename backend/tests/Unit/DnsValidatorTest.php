<?php

use App\Support\Domains\DnsValidator;

it('accepts a valid A record', function () {
    expect(DnsValidator::validate(['type' => 'A', 'name' => 'app', 'content' => '203.0.113.5']))->toBe([]);
});

it('rejects an invalid IPv4 address', function () {
    expect(DnsValidator::validate(['type' => 'A', 'content' => '999.1.1.1']))->not->toBe([]);
});

it('rejects an unsupported record type', function () {
    expect(DnsValidator::validate(['type' => 'UNKNOWN', 'content' => 'x']))->not->toBe([]);
});

it('validates MX priority and hostname', function () {
    expect(DnsValidator::validate(['type' => 'MX', 'content' => 'mail', 'priority' => 10]))->toBe([]);
    expect(DnsValidator::validate(['type' => 'MX', 'content' => 'mail']))->not->toBe([]);
});

it('validates SRV content', function () {
    expect(DnsValidator::validate(['type' => 'SRV', 'content' => '10 60 5060 sip']))->toBe([]);
    expect(DnsValidator::validate(['type' => 'SRV', 'content' => '10 60 sip']))->not->toBe([]);
});

it('validates CAA content', function () {
    expect(DnsValidator::validate(['type' => 'CAA', 'content' => '0 issue "letsencrypt.org"']))->toBe([]);
    expect(DnsValidator::validate(['type' => 'CAA', 'content' => 'issue letsencrypt']))->not->toBe([]);
});
