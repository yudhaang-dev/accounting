<?php

use App\Models\User;
use Illuminate\Support\Str;

function evToken(array $client, ?string $scope = null): string
{
    app('auth')->forgetGuards();

    return test()->post('/api/v1/oauth/token', ['grant_type' => 'client_credentials', 'scope' => $scope ?? implode(' ', $client['scopes'])],
        ['Authorization' => 'Basic '.base64_encode($client['client_id'].':'.$client['client_secret']), 'Accept' => 'application/json'])
        ->assertOk()->json('access_token');
}

/** Requests as the machine: only the Bearer token and no leftover session user. */
function evSend(string $method, string $uri, array $data = [], ?string $token = null)
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    return test()->json($method, $uri, $data, $token === null ? [] : ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]);
}
