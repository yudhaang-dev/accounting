<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function generatedSpec(): array
{
    Artisan::call('scramble:export', ['--api' => 'v1', '--stdout' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('documents the /api/v1 surface with both authentication schemes', function () {
    $spec = generatedSpec();

    expect($spec['openapi'])->toStartWith('3.')
        ->and($spec['info']['title'])->toBe('Accounting API')
        ->and($spec['servers'])->toBe([['url' => '/api/v1']])
        ->and($spec['paths'])->toHaveKey('/csrf-cookie')
        ->and($spec['components']['securitySchemes']['sessionCookie']['in'])->toBe('cookie')
        ->and($spec['components']['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer')
        ->and($spec['security'])->toBe([['sessionCookie' => []], ['bearerAuth' => []]]);
});

it('matches the committed openapi/openapi.json, which the SPA client is generated from', function () {
    $committed = json_decode(file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(generatedSpec())->toEqual($committed, 'The API changed: run `composer openapi:export` and `npm run api:types`, then commit both.');
});

it('restricts the rendered docs to local use or authenticated users', function () {
    app()['env'] = 'production';

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
    $this->get('/docs/v1')->assertForbidden();
    $this->get('/docs/v1.json')->assertForbidden();

    $this->actingAs(User::factory()->create())->get('/docs/v1.json')->assertOk();
});

it('serves one document per published API version, with its release number', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/docs/v1')->assertOk();
    $this->getJson('/docs/v1.json')
        ->assertOk()
        ->assertJsonPath('info.version', config('api.versions.v1.release'))
        ->assertJsonPath('servers.0.url', '/api/v1')
        ->assertJsonPath('paths./me.get.operationId', 'me');
});

it('redirects /docs/api to the current version', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/docs/api')->assertRedirect('/docs/v1');
    $this->get('/docs/api.json')->assertRedirect('/docs/v1.json');
});

it('keeps every published version documented', function () {
    foreach (array_keys(config('api.versions')) as $name) {
        expect(route("scramble.docs.{$name}.ui", absolute: false))->toBe("/docs/{$name}")
            ->and(route("scramble.docs.{$name}.document", absolute: false))->toBe("/docs/{$name}.json");
    }

    expect(config('api.versions'))->toHaveKey(config('api.current_version'));
});
