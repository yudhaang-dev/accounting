<?php

use App\Models\User;

test('password can be confirmed via json', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('password.confirm.store'), [
        'password' => 'password',
    ]);

    $response->assertCreated();
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('password.confirm.store'), [
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('password');
});

test('password confirmation requires authentication', function () {
    $response = $this->postJson(route('password.confirm.store'), [
        'password' => 'password',
    ]);

    $response->assertUnauthorized();
});
