<?php

use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use App\Support\Company\CompanyScopedModel;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TestParent extends CompanyScopedModel
{
    protected $table = 'md_companies_parent_test';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::create('md_companies_parent_test', function (Blueprint $table) {
        $table->ulid('id')->primary();
        CompanyKeys::scoped($table);
        $table->string('name');
    });

    Schema::create('md_companies_child_test', function (Blueprint $table) {
        $table->ulid('id')->primary();
        CompanyKeys::scoped($table);
        CompanyKeys::reference($table, 'parent_id', 'md_companies_parent_test');
    });

    $this->companyA = (string) Str::ulid();
    $this->companyB = (string) Str::ulid();
    foreach ([$this->companyA, $this->companyB] as $id) {
        DB::table(CompanyKeys::COMPANIES_TABLE)->insert([
            'id' => $id, 'name' => $id, 'functional_currency' => 'IDR', 'timezone' => 'Asia/Jakarta',
            'default_locale' => 'id', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

it('generates ULID keys', function () {
    app(CompanyContext::class)->set($this->companyA);

    $parent = TestParent::create(['name' => 'a']);

    expect(Str::isUlid($parent->id))->toBeTrue();
});

it('scopes queries to the current company and fills company_id on create', function () {
    $context = app(CompanyContext::class);

    $context->run($this->companyA, fn () => TestParent::create(['name' => 'a']));
    $context->run($this->companyB, fn () => TestParent::create(['name' => 'b']));

    $names = $context->run($this->companyA, fn () => TestParent::pluck('name')->all());

    expect($names)->toBe(['a'])
        ->and(TestParent::withoutGlobalScope(CompanyScope::class)->count())->toBe(2);
});

it('fails closed without a company context', function () {
    TestParent::query()->get();
})->throws(LogicException::class);

it('refuses to create a row for a different company than the context', function () {
    app(CompanyContext::class)->set($this->companyA);

    TestParent::create(['name' => 'x', 'company_id' => $this->companyB]);
})->throws(LogicException::class);

it('rejects a cross-company relation with the composite foreign key', function () {
    $parentId = (string) Str::ulid();
    DB::table('md_companies_parent_test')->insert(['id' => $parentId, 'company_id' => $this->companyA, 'name' => 'a']);

    $insert = fn (string $company) => DB::table('md_companies_child_test')->insert([
        'id' => (string) Str::ulid(),
        'company_id' => $company,
        'parent_id' => $parentId,
    ]);

    expect($insert($this->companyA))->toBeTrue()
        ->and(fn () => $insert($this->companyB))->toThrow(QueryException::class);
});
