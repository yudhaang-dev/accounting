<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function generatedSpec(): array
{
    Artisan::call('scramble:export', ['--stdout' => true]);

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

    $this->actingAs(User::factory()->create())->get('/docs/api.json')->assertOk();
});
