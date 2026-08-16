<?php

use App\Support\Security\Totp;

it('generates a base32 secret', function () {
    $secret = Totp::generateSecret();

    expect($secret)->toMatch('/^[A-Z2-7]+$/');
    expect(strlen($secret))->toBeGreaterThanOrEqual(32);
});

it('matches RFC 6238 test vectors', function () {
    // base32("12345678901234567890")
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    expect(Totp::verify($secret, '287082', 0, 59))->toBeTrue();
    expect(Totp::verify($secret, '081804', 0, 1111111109))->toBeTrue();
    expect(Totp::verify($secret, '050471', 0, 1111111111))->toBeTrue();
    expect(Totp::verify($secret, '005924', 0, 1234567890))->toBeTrue();
    expect(Totp::verify($secret, '279037', 0, 2000000000))->toBeTrue();
});

it('rejects an invalid code', function () {
    expect(Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '000000', 0, 59))->toBeFalse();
});
