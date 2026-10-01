<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge requires a pending login', function () {
    $response = $this->postJson(route('two-factor.login.store'), [
        'code' => '123456',
    ]);

    $response->assertUnprocessable();
});

test('two factor challenge succeeds with a valid recovery code', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->postJson(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertJson(['two_factor' => true]);

    $response = $this->postJson(route('two-factor.login.store'), [
        'recovery_code' => json_decode(decrypt($user->two_factor_recovery_codes), true)[0],
    ]);

    $response->assertNoContent();
    $this->assertAuthenticatedAs($user);
});
