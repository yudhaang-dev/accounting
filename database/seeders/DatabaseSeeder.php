<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Intentionally empty: accounts exist only through invitations (`access:invite-owner`).
     * Demo data lives in DemoSeeder, which is run explicitly.
     */
    public function run(): void {}
}
