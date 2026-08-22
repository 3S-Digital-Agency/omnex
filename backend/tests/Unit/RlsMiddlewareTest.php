<?php

use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    DB::statement("SELECT set_config('nexus.tenant_id', '', false)");
    DB::statement("SELECT set_config('nexus.user_id', '', false)");
});

function nexusGuc(string $name): ?string
{
    return DB::selectOne("SELECT current_setting('{$name}', true) AS value")->value;
}

it('sets the tenant and user GUCs for the request session', function () {
    Config::set('omnex.enforce_rls', true);

    $orgId = '11111111-1111-1111-1111-111111111111';
    $userId = '22222222-2222-2222-2222-222222222222';

    $set = new ReflectionMethod(ResolveTenant::class, 'setRlsContext');
    $set->invoke(new ResolveTenant, $orgId, $userId);

    expect(nexusGuc('nexus.tenant_id'))->toBe($orgId)
        ->and(nexusGuc('nexus.user_id'))->toBe($userId);
});

it('clears the nexus.* GUCs (not omnex.*) after the request', function () {
    Config::set('omnex.enforce_rls', true);

    $set = new ReflectionMethod(ResolveTenant::class, 'setRlsContext');
    $set->invoke(new ResolveTenant, '11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222');

    $clear = new ReflectionMethod(ResolveTenant::class, 'clearRlsContext');
    $clear->invoke(new ResolveTenant);

    expect(nexusGuc('nexus.tenant_id'))->toBe('')
        ->and(nexusGuc('nexus.user_id'))->toBe('');
});
