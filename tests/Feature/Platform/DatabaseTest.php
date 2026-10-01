<?php

use Illuminate\Support\Facades\DB;

it('runs on PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('has the btree_gist extension enabled', function () {
    $installed = DB::table('pg_extension')->where('extname', 'btree_gist')->exists();

    expect($installed)->toBeTrue();
});
