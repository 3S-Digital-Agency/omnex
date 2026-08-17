<?php

use App\Models\LandingPage;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

function lpPayload(array $overrides = []): array
{
    return array_merge([
        'slug' => 'launch-offer',
        'type' => 'offer',
        'status' => 'published',
        'content_en' => [
            ['kind' => 'hero', 'title' => 'Launch offer', 'subtitle' => 'Everything you need to start.', 'cta_label' => 'Start now'],
            ['kind' => 'offer', 'title' => 'Launch plan', 'description' => 'All features for the first year.', 'price' => '$0/mo', 'features' => ['Domains', 'Sites'], 'cta_label' => 'Claim the offer'],
        ],
        'content_fr' => [
            ['kind' => 'hero', 'title' => 'Offre de lancement', 'subtitle' => 'Tout ce qu’il vous faut pour démarrer.', 'cta_label' => 'Commencer'],
            ['kind' => 'offer', 'title' => 'Forfait lancement', 'description' => 'Toutes les fonctions pour la première année.', 'price' => '0 $/mois', 'features' => ['Domaines', 'Sites'], 'cta_label' => 'Profiter de l’offre'],
        ],
    ], $overrides);
}

function lpOwner(): User
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

function lpCreate(array $attributes = []): LandingPage
{
    return LandingPage::create(array_merge([
        'slug' => 'launch-offer',
        'type' => 'offer',
        'status' => 'published',
        'content_en' => lpPayload()['content_en'],
        'content_fr' => lpPayload()['content_fr'],
        'published_at' => now(),
    ], $attributes));
}

it('serves a published campaign page publicly', function () {
    lpCreate();

    $this->getJson('/api/v1/public/landing-pages/launch-offer')
        ->assertOk()
        ->assertJsonPath('data.slug', 'launch-offer')
        ->assertJsonPath('data.type', 'offer')
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.content_en.0.kind', 'hero')
        ->assertJsonPath('data.content_fr.1.kind', 'offer');
});

it('hides drafts and unknown slugs from the public endpoint', function () {
    lpCreate(['slug' => 'secret-draft', 'status' => 'draft', 'published_at' => null]);

    $this->getJson('/api/v1/public/landing-pages/secret-draft')->assertNotFound();
    $this->getJson('/api/v1/public/landing-pages/does-not-exist')->assertNotFound();
});

it('lets a platform owner manage campaign pages', function () {
    $owner = lpOwner();

    $created = $this->actingAs($owner)->postJson('/api/v1/landing-pages', lpPayload())
        ->assertCreated()
        ->assertJsonPath('data.slug', 'launch-offer')
        ->json('data');

    $id = $created['id'];

    $this->actingAs($owner)->getJson('/api/v1/landing-pages')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($owner)->patchJson("/api/v1/landing-pages/{$id}", lpPayload([
        'slug' => 'launch-v2',
        'status' => 'draft',
    ]))
        ->assertOk()
        ->assertJsonPath('data.slug', 'launch-v2')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.published_at', null);

    $this->actingAs($owner)->deleteJson("/api/v1/landing-pages/{$id}")
        ->assertOk();

    $this->assertDatabaseCount('landing_pages', 0);
});

it('publishes with a timestamp and clears it on unpublish', function () {
    $owner = lpOwner();

    $created = $this->actingAs($owner)->postJson('/api/v1/landing-pages', lpPayload())
        ->assertCreated()
        ->json('data');

    $this->assertNotNull($created['published_at']);

    $this->actingAs($owner)->patchJson('/api/v1/landing-pages/'.$created['id'], lpPayload(['status' => 'draft']))
        ->assertOk()
        ->assertJsonPath('data.published_at', null);
});

it('refuses management to a non-owner user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/landing-pages')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/landing-pages', lpPayload())->assertForbidden();

    lpCreate();
    $page = LandingPage::firstOrFail();
    $this->actingAs($user)->patchJson('/api/v1/landing-pages/'.$page->id, lpPayload())->assertForbidden();
    $this->actingAs($user)->deleteJson('/api/v1/landing-pages/'.$page->id)->assertForbidden();
});

it('requires authentication for management routes', function () {
    $this->getJson('/api/v1/landing-pages')->assertUnauthorized();
    $this->postJson('/api/v1/landing-pages', lpPayload())->assertUnauthorized();
});

it('validates the payload and section structure', function () {
    $owner = lpOwner();

    $this->actingAs($owner)->postJson('/api/v1/landing-pages', lpPayload(['slug' => 'Bad Slug!']))
        ->assertUnprocessable();

    $this->actingAs($owner)->postJson('/api/v1/landing-pages', lpPayload(['type' => 'webinar']))
        ->assertUnprocessable();

    $this->actingAs($owner)->postJson('/api/v1/landing-pages', lpPayload([
        'content_en' => [['kind' => 'hero', 'title' => 'Missing fields']],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content_en.0');

    $this->actingAs($owner)->postJson('/api/v1/landing-pages', lpPayload([
        'content_en' => [['kind' => 'mystery-section', 'title' => 'x']],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content_en.0.kind');

    $this->assertDatabaseCount('landing_pages', 0);
});

it('rejects a duplicate slug', function () {
    lpCreate();

    $owner = lpOwner();
    $this->actingAs($owner)->postJson('/api/v1/landing-pages', lpPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');
});

it('allows keeping the same slug when updating', function () {
    $owner = lpOwner();
    $page = lpCreate();

    $this->actingAs($owner)->patchJson('/api/v1/landing-pages/'.$page->id, lpPayload())
        ->assertOk()
        ->assertJsonPath('data.slug', 'launch-offer');
});
