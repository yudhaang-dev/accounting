<?php

use App\Platform\Audit\AuditEntry;
use App\Platform\Retention\Retained;
use App\Platform\Retention\Retention;
use App\Platform\Retention\RetentionGuard;
use App\Support\Eloquent\UlidModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RetainedThing extends UlidModel
{
    use Retained;

    protected $table = 'gl_retention_probe';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    $this->retention = app(Retention::class);
    $this->company = (string) Str::ulid();
});

describe('retention period (X-FR-05)', function () {
    it('defaults to ten years from configuration', function () {
        expect($this->retention->defaultYears())->toBe(10)
            ->and($this->retention->yearsFor($this->company))->toBe(10);
    });

    it('follows the configured default', function () {
        config(['retention.default_years' => 7, 'retention.minimum_years' => 5]);

        expect($this->retention->yearsFor($this->company))->toBe(7);
    });

    it('lets a company keep records longer, per company, and records the change', function () {
        $other = (string) Str::ulid();

        $this->retention->define($this->company, 15);

        expect($this->retention->yearsFor($this->company))->toBe(15)
            ->and($this->retention->yearsFor($other))->toBe(10);

        $audit = AuditEntry::where('event', 'retention.policy_changed')->where('company_id', $this->company)->sole();

        expect($audit->attribute_changes->all())->toBe(['old' => ['years' => 10], 'attributes' => ['years' => 15]]);
    });

    it('can change an existing policy', function () {
        $this->retention->define($this->company, 12);
        $this->retention->define($this->company, 20);

        expect($this->retention->yearsFor($this->company))->toBe(20)
            ->and(DB::table('pf_retention_policies')->count())->toBe(1);
    });

    it('refuses a period shorter than the legal minimum', function () {
        $this->retention->define($this->company, 9);
    })->throws(InvalidArgumentException::class);

    it('computes when a record may be deleted', function () {
        $date = CarbonImmutable::parse('2026-03-15');

        expect($this->retention->retainUntil($this->company, $date)->toDateString())->toBe('2036-03-15')
            ->and($this->retention->hasExpired($this->company, $date, CarbonImmutable::parse('2036-03-14')))->toBeFalse()
            ->and($this->retention->hasExpired($this->company, $date, CarbonImmutable::parse('2036-03-15')))->toBeTrue();
    });

    it('errs on the side of keeping a record dated 29 February', function () {
        $until = $this->retention->retainUntil($this->company, CarbonImmutable::parse('2024-02-29'));

        // 2034 has no 29 February; the later day is chosen, never the earlier one.
        expect($until->toDateString())->toBe('2034-03-01');
    });
});

describe('no hard delete of financial data (X-FR-05)', function () {
    beforeEach(function () {
        Schema::create('gl_retention_probe', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('note');
        });
        RetentionGuard::protect('gl_retention_probe');

        DB::table('gl_retention_probe')->insert(['id' => (string) Str::ulid(), 'note' => 'keep me']);
    });

    it('still allows inserts and updates', function () {
        DB::table('gl_retention_probe')->insert(['id' => (string) Str::ulid(), 'note' => 'another']);
        DB::table('gl_retention_probe')->where('note', 'another')->update(['note' => 'changed']);

        expect(DB::table('gl_retention_probe')->count())->toBe(2);
    });

    it('rejects DELETE and TRUNCATE at the database level', function () {
        expect(fn () => DB::transaction(fn () => DB::table('gl_retention_probe')->delete()))
            ->toThrow(QueryException::class, 'retention period');
        expect(fn () => DB::transaction(fn () => DB::table('gl_retention_probe')->truncate()))
            ->toThrow(QueryException::class, 'retention period');

        expect(DB::table('gl_retention_probe')->count())->toBe(1);
    });

    it('allows deletion only inside the purge transaction, and only there', function () {
        $deleted = $this->retention->purging(fn () => DB::table('gl_retention_probe')->delete());

        expect($deleted)->toBe(1)
            ->and(DB::table('gl_retention_probe')->count())->toBe(0);

        DB::table('gl_retention_probe')->insert(['id' => (string) Str::ulid(), 'note' => 'after purge']);

        // The permission does not leak out of the purge transaction.
        expect(fn () => DB::transaction(fn () => DB::table('gl_retention_probe')->delete()))
            ->toThrow(QueryException::class);
    });

    it('explains the rule before the database does when a model is deleted', function () {
        $thing = RetainedThing::create(['note' => 'x']);

        expect(fn () => $thing->delete())->toThrow(LogicException::class, 'retention period');
    });

    it('lists protected tables and spots financial tables that lack the guard', function () {
        expect(RetentionGuard::protectedTables())->toContain('gl_retention_probe');

        Schema::create('sl_forgotten', function (Blueprint $table) {
            $table->ulid('id')->primary();
        });

        expect(RetentionGuard::unprotectedFinancialTables())->toBe(['sl_forgotten']);

        RetentionGuard::protect('sl_forgotten');

        expect(RetentionGuard::unprotectedFinancialTables())->toBe([]);
    });

    it('lets a reviewed exception stay unprotected', function () {
        Schema::create('te_scratch', function (Blueprint $table) {
            $table->ulid('id')->primary();
        });
        config(['retention.unprotected_tables' => ['te_scratch']]);

        expect(RetentionGuard::unprotectedFinancialTables())->toBe([]);
    });

    it('rejects table names that are not plain identifiers', function () {
        RetentionGuard::protect('gl_x; drop table users');
    })->throws(InvalidArgumentException::class);
});

it('has the retention guard on every financial table that exists today', function () {
    expect(RetentionGuard::unprotectedFinancialTables())->toBe([]);
});
