<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => $this->withoutVite());

it('serves the SPA shell for client-side routes', function (string $path) {
    $this->get($path)->assertOk()->assertSee('id="root"', false);
})->with(['/login', '/forgot-password', '/reset-password/abc123', '/two-factor-challenge', '/email/verify', '/some/deep/link']);

it('keeps the named routes Fortify and Laravel link to', function () {
    foreach (['login', 'password.request', 'password.reset', 'verification.notice'] as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }
});

it('answers JSON 404 for unknown API paths instead of the HTML shell', function () {
    $this->get('/api/v1/does-not-exist')->assertNotFound()->assertJson(['code' => 'NOT_FOUND']);
});

it('redirects signed-in users away from the login screen', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect();
});

describe('GET /api/v1/me', function () {
    it('returns the signed-in user', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => true,
                'two_factor_enabled' => false,
            ]]);
    });

    it('reports two-factor and unverified e-mail state', function () {
        $user = User::factory()->withTwoFactor()->unverified()->create();

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertJsonPath('data.two_factor_enabled', true)
            ->assertJsonPath('data.email_verified', false);
    });

    it('answers 401 UNAUTHENTICATED without a session', function () {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJson(['code' => 'UNAUTHENTICATED']);
    });
});
