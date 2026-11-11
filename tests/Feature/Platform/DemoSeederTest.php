<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;

it('refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
        ->expectsOutputToContain('production')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(0);
});

it('leaves the default seeder without accounts', function () {
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::query()->count())->toBe(0);
});
