<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

// The JSON routes the account screen drives to set up two-step verification (IA-FR-04, D2d3b).

function twoFactorUser(): User
{
    return User::factory()->create(['password' => 'Secret-pass-123']);
}

describe('two-step verification setup (IA-FR-04)', function () {
    it('asks to confirm the password first, with a status the SPA can recognise', function () {
        $this->actingAs(twoFactorUser())->postJson('/user/two-factor-authentication')->assertStatus(423);
    });

    it('rejects a wrong password and accepts the right one', function () {
        $this->actingAs(twoFactorUser());

        $this->postJson('/user/confirm-password', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/user/confirm-password', ['password' => 'Secret-pass-123'])->assertSuccessful();
        $this->getJson('/user/confirmed-password-status')->assertJsonPath('confirmed', true);
    });

    it('enables with a QR code, only counts after a valid code, and then shows recovery codes', function () {
        $user = twoFactorUser();
        $this->actingAs($user)->postJson('/user/confirm-password', ['password' => 'Secret-pass-123']);

        $this->postJson('/user/two-factor-authentication')->assertSuccessful();

        expect($this->getJson('/api/v1/me')->json('data.two_factor_enabled'))->toBeFalse();
        $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg', 'url']);
        $secret = $this->getJson('/user/two-factor-secret-key')->assertOk()->json('secretKey');

        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $code])->assertSuccessful();

        expect($this->getJson('/api/v1/me')->json('data.two_factor_enabled'))->toBeTrue();
        expect($this->getJson('/user/two-factor-recovery-codes')->assertOk()->json())->toHaveCount(8);
        expect($this->postJson('/user/two-factor-recovery-codes')->assertSuccessful())->not->toBeNull();

        $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();
        expect($this->getJson('/api/v1/me')->json('data.two_factor_enabled'))->toBeFalse();
    });
});
