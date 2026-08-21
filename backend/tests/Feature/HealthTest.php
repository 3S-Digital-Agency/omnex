<?php

use Illuminate\Support\Facades\DB;

it('exposes a public health endpoint without auth', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('db', 'ok')
        ->assertJsonStructure([
            'status', 'service', 'version', 'environment', 'db', 'time',
        ]);
});

it('reports degraded when the database is unreachable', function () {
    DB::shouldReceive('select')->once()->andThrow(new RuntimeException('down'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('db', 'unavailable');
});
