<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Support\Access\DataScopeContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Expression;

/**
 * Hides entries from a user whose data scope (IA-FR-03) does not cover every line of the entry: an
 * entry is one document and is read, changed, approved and posted as a whole, so a line outside the
 * scope puts the whole entry outside it. Entries then read as not found, for the list, one entry
 * and every action alike. Without a restricted scope in the request, nothing is filtered.
 *
 * @implements Scope<Model>
 */
final class WithinDataScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $allowed = app(DataScopeContext::class)->current()->allowed();

        if ($allowed === []) {
            return;
        }

        $conditions = [];
        $bindings = [];

        foreach ($allowed as $typeKey => $valueIds) {
            // `->>` of a missing key is NULL, which `in` turns into NULL, so a line without the
            // dimension is outside the scope once coalesced to false below.
            $conditions[] = '(entry_line.line->\'dimensions\'->>(?::text)) in ('.implode(', ', array_fill(0, count($valueIds), '?')).')';
            $bindings = [...$bindings, $typeKey, ...$valueIds];
        }

        // The SQL is built from this scope's own fragments and placeholders only; type keys and value
        // IDs travel as bindings.
        $builder->whereRaw(
            new Expression('not exists (select 1 from jsonb_array_elements(te_entries.lines) as entry_line(line) where not coalesce('.implode(' and ', $conditions).', false))'),
            $bindings,
        );
    }
}
