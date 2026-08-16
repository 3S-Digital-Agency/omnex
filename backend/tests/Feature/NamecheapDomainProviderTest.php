<?php

use App\Support\Domains\DomainProviderException;
use App\Support\Domains\DomainUnavailableException;
use App\Support\Domains\Providers\NamecheapDomainProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('namecheap.endpoint', 'https://api.namecheap.com/xml.response');
    config()->set('namecheap.api_user', 'testuser');
    config()->set('namecheap.api_key', 'testkey');
    config()->set('namecheap.username', 'testuser');
    config()->set('namecheap.client_ip', '127.0.0.1');
    config()->set('namecheap.registrant', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'address1' => '1 Main St',
        'city' => 'Springfield',
        'state_province' => 'IL',
        'postal_code' => '62701',
        'country' => 'US',
        'phone' => '+1.5551234567',
        'email_address' => 'john@example.com',
    ]);
});

function namecheapProvider(): NamecheapDomainProvider
{
    return new NamecheapDomainProvider;
}

function namecheapXml(string $command, string $body): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">'
        .'<Errors /><RequestedCommand>'.$command.'</RequestedCommand>'
        .'<CommandResponse Type="'.$command.'">'.$body.'</CommandResponse>'
        .'</ApiResponse>';
}

it('reports configured state from credentials', function () {
    expect(namecheapProvider()->isConfigured())->toBeTrue();

    config()->set('namecheap.api_key', '');

    expect(namecheapProvider()->isConfigured())->toBeFalse();
});

it('rejects calls before credentials are set', function () {
    config()->set('namecheap.api_key', '');

    namecheapProvider()->checkAvailability('acme.com');
})->throws(DomainProviderException::class);

it('searches availability across tlds', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(namecheapXml('namecheap.domains.check',
            '<DomainCheckResult Domain="acme.com" Available="true" Premium="false" />'
            .'<DomainCheckResult Domain="acme.net" Available="false" Premium="false" />')),
    ]);

    $results = namecheapProvider()->search('acme', ['com', 'net']);

    expect($results)->toHaveCount(2);
    expect($results[0])->toMatchArray([
        'domain' => 'acme.com',
        'tld' => 'com',
        'available' => true,
        'premium' => false,
    ]);
    expect($results[1]['available'])->toBeFalse();

    Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.check'
        && $request['DomainList'] === 'acme.com,acme.net');
});

it('checks availability of a single domain', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(namecheapXml('namecheap.domains.check',
            '<DomainCheckResult Domain="example.com" Available="true" Premium="false" />')),
    ]);

    expect(namecheapProvider()->checkAvailability('example.com')['available'])->toBeTrue();
});

it('registers a domain with registrant contacts', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(namecheapXml('namecheap.domains.create',
            '<DomainCreateResult Domain="acme.com" Registered="true" />')),
    ]);

    $result = namecheapProvider()->register('acme.com', ['years' => 1]);

    expect($result['external_id'])->toBe('acme.com');
    expect($result['expires_at'])->not->toBeNull();

    Http::assertSent(function ($request) {
        return $request['Command'] === 'namecheap.domains.create'
            && $request['DomainName'] === 'acme.com'
            && $request['FirstName'] === 'John'
            && $request['EmailAddress'] === 'john@example.com';
    });
});

it('throws when the registration is not confirmed', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(namecheapXml('namecheap.domains.create',
            '<DomainCreateResult Domain="acme.com" Registered="false" />')),
    ]);

    namecheapProvider()->register('acme.com');
})->throws(DomainProviderException::class);

it('renews a domain', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(namecheapXml('namecheap.domains.renew',
            '<DomainRenewResult Domain="acme.com" Renewed="true" />')),
    ]);

    $result = namecheapProvider()->renew('acme.com', 2);

    expect($result['expires_at'])->not->toBeNull();
    Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.renew' && $request['Years'] == 2);
});

it('transfers a domain with an auth code', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(namecheapXml('namecheap.domains.transfer',
            '<DomainTransferResult Domain="acme.com" TransferID="77" IsSuccess="true" />')),
    ]);

    $result = namecheapProvider()->transfer('acme.com', 'ABC123XYZ');

    expect($result['external_id'])->toBe('acme.com');
    Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.transfer'
        && $request['TransferCode'] === 'ABC123XYZ');
});

it('parses domain details and nameservers', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(namecheapXml('namecheap.domains.getInfo',
            '<DomainGetInfoResult Domain="acme.com" ID="42" Status="ACTIVE" Locked="true" WhoisGuard="ENABLED">'
            .'<NameServers><NameServer>ns1.example.com</NameServer><NameServer>ns2.example.com</NameServer></NameServers>'
            .'<DomainDetails><ExpiredDate>2027-08-16</ExpiredDate></DomainDetails>'
            .'</DomainGetInfoResult>')),
    ]);

    $details = namecheapProvider()->getDetails('acme.com');

    expect($details['external_id'])->toBe('42');
    expect($details['status'])->toBe('active');
    expect($details['transfer_lock'])->toBeTrue();
    expect($details['privacy_enabled'])->toBeTrue();
    expect($details['nameservers'])->toBe(['ns1.example.com', 'ns2.example.com']);
    expect($details['expires_at'])->toBe('2027-08-16');
});

it('updates contacts, privacy, lock and nameservers', function () {
    Http::fake(function ($request) {
        return match ($request['Command']) {
            'namecheap.domains.setContacts' => Http::response(namecheapXml('namecheap.domains.setContacts',
                '<DomainSetContactsResult Domain="acme.com" IsSuccess="true" />')),
            'namecheap.domains.setWhoisGuard' => Http::response(namecheapXml('namecheap.domains.setWhoisGuard',
                '<DomainSetWhoisGuardResult Domain="acme.com" IsSuccess="true" />')),
            'namecheap.domains.setRegistrarLock' => Http::response(namecheapXml('namecheap.domains.setRegistrarLock',
                '<DomainSetRegistrarLockResult Domain="acme.com" IsSuccess="true" />')),
            'namecheap.domains.ns.update' => Http::response(namecheapXml('namecheap.domains.ns.update',
                '<DomainNsUpdateResult Domain="acme.com" IsSuccess="true" />')),
            default => Http::response('Unexpected request', 500),
        };
    });

    expect(namecheapProvider()->updateContacts('acme.com', ['first_name' => 'Jane'])['contacts']['first_name'])
        ->toBe('Jane');
    Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.setContacts'
        && $request['FirstName'] === 'Jane');

    expect(namecheapProvider()->setPrivacy('acme.com', true)['privacy_protection'])->toBeTrue();
    Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.setWhoisGuard'
        && $request['WgEnabled'] === 'ENABLE');

    expect(namecheapProvider()->setTransferLock('acme.com', true)['transfer_lock'])->toBeTrue();
    Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.setRegistrarLock'
        && $request['LockAction'] === 'LOCK');

    expect(namecheapProvider()->setNameservers('acme.com', ['ns1.example.com', 'ns2.example.com'])['nameservers'])
        ->toBe(['ns1.example.com', 'ns2.example.com']);
    Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.ns.update'
        && $request['NameServer1'] === 'ns1.example.com');
});

it('maps an unavailable domain error to DomainUnavailableException', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(
            '<?xml version="1.0" encoding="utf-8"?>'
            .'<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">'
            .'<Errors><Error Number="2015189">Domain is not available</Error></Errors>'
            .'<RequestedCommand>namecheap.domains.create</RequestedCommand>'
            .'</ApiResponse>',
            200
        ),
    ]);

    namecheapProvider()->register('acme.com');
})->throws(DomainUnavailableException::class);

it('throws a provider exception on other API errors', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response(
            '<?xml version="1.0" encoding="utf-8"?>'
            .'<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">'
            .'<Errors><Error Number="2019001">Authentication failed</Error></Errors>'
            .'<RequestedCommand>namecheap.domains.check</RequestedCommand>'
            .'</ApiResponse>',
            200
        ),
    ]);

    namecheapProvider()->checkAvailability('acme.com');
})->throws(DomainProviderException::class);

it('throws a provider exception on a failed HTTP response', function () {
    Http::fake([
        'api.namecheap.com/*' => Http::response('Server Error', 500),
    ]);

    namecheapProvider()->checkAvailability('acme.com');
})->throws(DomainProviderException::class);
