<?php

/*
 * Creates the verified user the end-to-end scenarios sign in as. Run by e2e/support/prepare.ts after
 * the migrations, with E2E_NAME, E2E_EMAIL and E2E_PASSWORD in the environment.
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

User::factory()->create([
    'name' => getenv('E2E_NAME'),
    'email' => getenv('E2E_EMAIL'),
    'password' => Hash::make((string) getenv('E2E_PASSWORD')),
]);
