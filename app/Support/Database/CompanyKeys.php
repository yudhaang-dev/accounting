<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Migration helpers for the composite foreign key convention (ADR-0003, P-05).
 *
 * Every company-scoped table has `company_id` and a unique `(company_id, id)`; a relation to
 * another company-scoped table is a composite foreign key `(company_id, <column>)`, so a row can
 * never point at another company's row.
 */
final class CompanyKeys
{
    public const COMPANIES_TABLE = 'md_companies';

    /** Adds `company_id` (FK to the companies table) and the unique `(company_id, id)` target key. */
    public static function scoped(Blueprint $table): void
    {
        $table->foreignUlid('company_id')->constrained(self::COMPANIES_TABLE);
        $table->unique(['company_id', 'id']);
    }

    /** Adds a ULID column referencing `$referencedTable` within the same company. */
    public static function reference(Blueprint $table, string $column, string $referencedTable, bool $nullable = false): void
    {
        $definition = $table->ulid($column);

        if ($nullable) {
            $definition->nullable();
        }

        $table->foreign(['company_id', $column])->references(['company_id', 'id'])->on($referencedTable);
    }
}
