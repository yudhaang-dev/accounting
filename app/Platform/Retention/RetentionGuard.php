<?php

namespace App\Platform\Retention;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Migration helper that makes a table refuse hard deletes (X-FR-05). Call it right after creating
 * any table that holds financial data, attachments or audit:
 *
 *     Schema::create('gl_journals', ...);
 *     RetentionGuard::protect('gl_journals');
 *
 * An architecture test fails for any gl_, sl_ or te_ table that lacks the guard.
 */
final class RetentionGuard
{
    public static function protect(string $table): void
    {
        self::assertIdentifier($table);

        DB::statement("CREATE TRIGGER {$table}_retention_no_delete BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION pf_reject_hard_delete()");
        DB::statement("CREATE TRIGGER {$table}_retention_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION pf_reject_hard_delete()");
    }

    /**
     * Financial tables (by prefix) that lack the guard and are not a listed exception.
     *
     * @return list<string>
     */
    public static function unprotectedFinancialTables(): array
    {
        /** @var list<string> $prefixes */
        $prefixes = config('retention.protected_prefixes');
        /** @var list<string> $exempt */
        $exempt = config('retention.unprotected_tables');
        $protected = self::protectedTables();

        $tables = array_filter(
            Schema::getTableListing(schemaQualified: false),
            fn (string $table) => Str::startsWith($table, $prefixes) && ! in_array($table, [...$protected, ...$exempt], true),
        );

        return array_values($tables);
    }

    /**
     * Tables (in the current schema) that carry the retention guard.
     *
     * @return list<string>
     */
    public static function protectedTables(): array
    {
        $names = DB::table('pg_trigger as t')
            ->join('pg_class as c', 'c.oid', '=', 't.tgrelid')
            ->join('pg_proc as p', 'p.oid', '=', 't.tgfoid')
            ->where('p.proname', 'pf_reject_hard_delete')
            ->where('t.tgisinternal', false)
            ->distinct()
            ->pluck('c.relname')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();

        return array_values($names);
    }

    private static function assertIdentifier(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name [{$table}].");
        }
    }
}
