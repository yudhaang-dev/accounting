<?php

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Every instant the ledger records (audit trail, approval decisions, posting time, idempotency expiry)
 * must be the moment it really happened, whatever time zone the PostgreSQL server runs in. Laravel
 * writes the clock of its own time zone (UTC) without an offset, so the connection has to say what
 * zone that clock is in; otherwise a server set to another zone reads it as local time and every
 * `timestamptz` instant is off by the gap between the two.
 */
function recordedAuditEntry(): object
{
    $user = User::factory()->create();

    app(CompanyContext::class)->run((string) Str::ulid(), fn () => app(Audit::class)->record(
        event: 'period.closed',
        description: 'Period closed',
        actor: $user,
    ));

    return DB::selectOne('select extract(epoch from created_at) as at from pf_audit_log order by created_at desc limit 1');
}

it('stores the instant an audit entry was written, not the same clock in the server time zone', function () {
    $stored = (float) recordedAuditEntry()->at;

    expect(abs($stored - now()->getTimestamp()))->toBeLessThan(120);
});

it('reads an audit instant back as the moment it happened', function () {
    recordedAuditEntry();

    $entry = DB::table('pf_audit_log')->latest('created_at')->first();

    expect(abs(Carbon::parse($entry->created_at)->getTimestamp() - now()->getTimestamp()))->toBeLessThan(120);
});
