<?php

use App\Models\ContactLead;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

function leadPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'company' => 'Acme Inc.',
        'subject' => 'Quote request',
        'message' => 'We would like a quote for 5 VPS servers and a managed DNS zone.',
    ], $overrides);
}

function ownerContext(): User
{
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    return $owner;
}

it('stores a public lead and notifies platform owners', function () {
    $owner = ownerContext();

    $this->postJson('/api/v1/public/leads', leadPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Jane Doe')
        ->assertJsonPath('data.email', 'jane@example.com')
        ->assertJsonPath('data.status', 'new');

    $this->assertDatabaseHas('contact_leads', [
        'email' => 'jane@example.com',
        'status' => ContactLead::STATUS_NEW,
    ]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $owner->id,
        'type' => 'lead',
    ]);
});

it('rejects spam through the honeypot field', function () {
    $this->postJson('/api/v1/public/leads', leadPayload(['website' => 'spam-link']))
        ->assertUnprocessable();

    $this->assertDatabaseCount('contact_leads', 0);
});

it('validates the contact payload', function () {
    $this->postJson('/api/v1/public/leads', leadPayload(['email' => 'not-an-email']))
        ->assertUnprocessable();

    $this->postJson('/api/v1/public/leads', leadPayload(['message' => 'short']))
        ->assertUnprocessable();

    $this->postJson('/api/v1/public/leads', [])
        ->assertUnprocessable();

    $this->assertDatabaseCount('contact_leads', 0);
});

it('does not notify when no platform owner exists', function () {
    User::factory()->create(); // non-owner user
    Organization::factory()->create(); // org without memberships

    $this->postJson('/api/v1/public/leads', leadPayload())
        ->assertCreated();

    $this->assertDatabaseCount('notifications', 0);
});

it('lists leads for authenticated platform owners', function () {
    $owner = ownerContext();

    $this->postJson('/api/v1/public/leads', leadPayload());

    $this->actingAs($owner)
        ->getJson('/api/v1/public/leads')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'Quote request');
});

it('records the visitor IP and user agent', function () {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (anti-spam-test)')
        ->postJson('/api/v1/public/leads', leadPayload())
        ->assertCreated();

    $this->assertDatabaseHas('contact_leads', [
        'email' => 'jane@example.com',
        'user_agent' => 'Mozilla/5.0 (anti-spam-test)',
    ]);
});

it('rate-limits submissions per IP', function () {
    config(['omnex.leads.rate_limit_max' => 3]);

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/public/leads', leadPayload(['email' => "jane{$i}@example.com"]))
            ->assertCreated();
    }

    $this->postJson('/api/v1/public/leads', leadPayload(['email' => 'jane4@example.com']))
        ->assertStatus(429);

    $this->assertDatabaseCount('contact_leads', 3);
});

it('rejects a missing recaptcha token when recaptcha is enabled', function () {
    config(['omnex.leads.recaptcha_secret' => 'test-secret']);

    $this->postJson('/api/v1/public/leads', leadPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recaptcha');

    $this->assertDatabaseCount('contact_leads', 0);
});

it('rejects a low-score recaptcha response', function () {
    config(['omnex.leads.recaptcha_secret' => 'test-secret']);
    Http::fake([
        'https://www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'score' => 0.2,
        ]),
    ]);

    $this->postJson('/api/v1/public/leads', leadPayload(['recaptcha_token' => 'low-score']))
        ->assertUnprocessable();

    $this->assertDatabaseCount('contact_leads', 0);
});

it('accepts a high-score recaptcha response', function () {
    config(['omnex.leads.recaptcha_secret' => 'test-secret']);
    Http::fake([
        'https://www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'score' => 0.9,
        ]),
    ]);

    $this->postJson('/api/v1/public/leads', leadPayload(['recaptcha_token' => 'high-score']))
        ->assertCreated();

    $this->assertDatabaseCount('contact_leads', 1);
});
