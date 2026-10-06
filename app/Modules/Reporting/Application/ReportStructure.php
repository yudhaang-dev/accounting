<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountData;
use Illuminate\Validation\ValidationException;

/**
 * The shape of a report definition version (RP-FR-01, RP-FR-02): rows, columns and the value basis.
 * Validation resolves account sets against the chart, because a row that names a missing account or
 * claims an account that another row already claims would make the total of the report wrong
 * (RP-AC-07). The structure is data; no formula language and no code (ADR-0009).
 */
final class ReportStructure
{
    /** Definition kinds the engine treats specially; any other kind is a plain report. */
    public const CUSTOM = 'custom';

    public const INCOME_STATEMENT = 'income_statement';

    public const BASES = ['balance', 'movement', 'opening', 'closing'];

    public const ROW_TYPES = ['heading', 'accounts', 'subtotal'];

    public const COLUMN_KINDS = ['period', 'ytd', 'as_of', 'difference'];

    /** Key of the row that holds the accounts no row claims (RP-FR-02). */
    public const UNMAPPED = 'unmapped';

    private const MAX_ROWS = 500;

    private const MAX_COLUMNS = 12;

    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param  array<string, mixed>  $input
     * @param  list<AccountData>  $accounts  the chart of the company
     * @return array{basis: string, rows: list<array<string, mixed>>, columns: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function normalize(array $input, array $accounts): array
    {
        $errors = [];
        $basis = self::basis($input['basis'] ?? 'movement', 'structure.basis', $errors);
        $rows = self::rows($input['rows'] ?? [], $basis, $accounts, $errors);
        $columns = self::columns($input['columns'] ?? [], $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['basis' => $basis, 'rows' => $rows, 'columns' => $columns];
    }

    /**
     * The accounts that a selector list stands for: an account itself, a parent with everything
     * below it, or a class (RP-FR-02). Never a range of codes.
     *
     * @param  list<array<string, mixed>>  $selectors
     * @param  list<AccountData>  $accounts
     * @return list<string> account IDs
     */
    public static function resolve(array $selectors, array $accounts): array
    {
        $children = [];

        foreach ($accounts as $account) {
            $children[$account->parentId ?? ''][] = $account->id;
        }

        $ids = [];

        foreach ($selectors as $selector) {
            if ($selector['type'] === 'account') {
                $ids[] = $selector['id'];
            } elseif ($selector['type'] === 'parent') {
                array_push($ids, ...self::subtree($selector['id'], $children));
            } else {
                array_push($ids, ...array_map(
                    fn (AccountData $account): string => $account->id,
                    array_filter($accounts, fn (AccountData $account): bool => $account->class->value === $selector['class']),
                ));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, list<string>>  $children
     * @return list<string>
     */
    private static function subtree(string $id, array $children): array
    {
        $ids = [$id];

        foreach ($children[$id] ?? [] as $child) {
            array_push($ids, ...self::subtree($child, $children));
        }

        return $ids;
    }

    /** @param  array<string, list<array<mixed>|string>>  $errors */
    private static function basis(mixed $value, string $field, array &$errors): string
    {
        if (! is_string($value) || ! in_array($value, self::BASES, true)) {
            $errors[$field][] = __('reports.basis_invalid', ['allowed' => implode(', ', self::BASES)]);

            return 'movement';
        }

        return $value;
    }

    /**
     * @param  list<AccountData>  $accounts
     * @param  array<string, list<array<mixed>|string>>  $errors
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $input, string $defaultBasis, array $accounts, array &$errors): array
    {
        if (! is_array($input) || $input === [] || count($input) > self::MAX_ROWS) {
            $errors['structure.rows'][] = __('reports.rows_count', ['max' => self::MAX_ROWS]);

            return [];
        }

        $byId = [];

        foreach ($accounts as $account) {
            $byId[$account->id] = $account;
        }

        $rows = [];
        $seen = [];
        $claimed = [];

        foreach (array_values($input) as $index => $row) {
            $at = "structure.rows.{$index}";
            $row = is_array($row) ? $row : [];
            $key = $row['key'] ?? null;

            if (! is_string($key) || preg_match(self::KEY, $key) !== 1 || $key === self::UNMAPPED) {
                $errors["{$at}.key"][] = __('reports.row_key_invalid');
            } elseif (isset($seen[$key])) {
                $errors["{$at}.key"][] = __('reports.row_key_repeated', ['key' => $key]);
            }

            $label = $row['label'] ?? null;

            if (! is_string($label) || $label === '' || mb_strlen($label) > 255) {
                $errors["{$at}.label"][] = __('reports.row_label_invalid');
            }

            $type = $row['type'] ?? null;

            if (! is_string($type) || ! in_array($type, self::ROW_TYPES, true)) {
                $errors["{$at}.type"][] = __('reports.row_type_invalid', ['allowed' => implode(', ', self::ROW_TYPES)]);
                $type = 'heading';
            }

            $normalized = ['key' => (string) $key, 'label' => (string) $label, 'type' => $type];

            if ($type !== 'heading') {
                $sign = $row['sign'] ?? 'debit';

                if (! in_array($sign, ['debit', 'credit'], true)) {
                    $errors["{$at}.sign"][] = __('reports.sign_invalid');
                    $sign = 'debit';
                }

                $normalized['sign'] = $sign;
            }

            if ($type === 'accounts') {
                $normalized['basis'] = isset($row['basis']) ? self::basis($row['basis'], "{$at}.basis", $errors) : $defaultBasis;
                $normalized['accounts'] = self::selectors($row['accounts'] ?? null, $at, $byId, $errors);

                foreach (self::resolve($normalized['accounts'], $accounts) as $id) {
                    $account = $byId[$id] ?? null;

                    if ($account === null || ! $account->isLeaf) {
                        continue;
                    }

                    if (isset($claimed[$id])) {
                        $errors["{$at}.accounts"][] = __('reports.account_claimed', ['code' => $account->code, 'row' => $claimed[$id]]);
                    } else {
                        $claimed[$id] = (string) $key;
                    }
                }
            }

            if ($type === 'subtotal') {
                $normalized['terms'] = self::terms($row['terms'] ?? null, $at, $seen, $errors);
            }

            if (is_string($key)) {
                $seen[$key] = true;
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param  array<string, AccountData>  $byId
     * @param  array<string, list<array<mixed>|string>>  $errors
     * @return list<array<string, string>>
     */
    public static function selectors(mixed $input, string $at, array $byId, array &$errors): array
    {
        if (! is_array($input) || $input === []) {
            $errors["{$at}.accounts"][] = __('reports.selectors_required');

            return [];
        }

        $selectors = [];

        foreach (array_values($input) as $i => $selector) {
            $field = "{$at}.accounts.{$i}";
            $type = is_array($selector) ? ($selector['type'] ?? null) : null;

            if (in_array($type, ['account', 'parent'], true)) {
                $id = $selector['id'] ?? null;

                if (! is_string($id) || ! isset($byId[$id])) {
                    $errors["{$field}.id"][] = __('reports.account_unknown');
                } else {
                    $selectors[] = ['type' => $type, 'id' => $id];
                }
            } elseif ($type === 'class') {
                $class = $selector['class'] ?? null;

                if (! is_string($class) || AccountClass::tryFrom($class) === null) {
                    $errors["{$field}.class"][] = __('reports.class_invalid');
                } else {
                    $selectors[] = ['type' => 'class', 'class' => $class];
                }
            } else {
                $errors["{$field}.type"][] = __('reports.selector_type_invalid');
            }
        }

        return $selectors;
    }

    /**
     * Terms refer to rows above only, so a subtotal never depends on itself.
     *
     * @param  array<string, true>  $seen  keys of the rows above
     * @param  array<string, list<array<mixed>|string>>  $errors
     * @return list<array{row: string, sign: int}>
     */
    private static function terms(mixed $input, string $at, array $seen, array &$errors): array
    {
        if (! is_array($input) || $input === []) {
            $errors["{$at}.terms"][] = __('reports.terms_required');

            return [];
        }

        $terms = [];

        foreach (array_values($input) as $i => $term) {
            $row = is_array($term) ? ($term['row'] ?? null) : null;
            $sign = is_array($term) ? ($term['sign'] ?? 1) : 1;

            if (! is_string($row) || (! isset($seen[$row]) && $row !== self::UNMAPPED)) {
                $errors["{$at}.terms.{$i}.row"][] = __('reports.term_row_unknown');
            }

            if (! in_array($sign, [1, -1], true)) {
                $errors["{$at}.terms.{$i}.sign"][] = __('reports.term_sign_invalid');
            }

            $terms[] = ['row' => (string) $row, 'sign' => (int) $sign];
        }

        return $terms;
    }

    /**
     * @param  array<string, list<array<mixed>|string>>  $errors
     * @return list<array<string, mixed>>
     */
    private static function columns(mixed $input, array &$errors): array
    {
        if (! is_array($input) || $input === [] || count($input) > self::MAX_COLUMNS) {
            $errors['structure.columns'][] = __('reports.columns_count', ['max' => self::MAX_COLUMNS]);

            return [];
        }

        $columns = [];
        $seen = [];

        foreach (array_values($input) as $index => $column) {
            $at = "structure.columns.{$index}";
            $column = is_array($column) ? $column : [];
            $key = $column['key'] ?? null;

            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                $errors["{$at}.key"][] = __('reports.column_key_invalid');
            } elseif (isset($seen[$key])) {
                $errors["{$at}.key"][] = __('reports.column_key_repeated', ['key' => $key]);
            }

            $label = $column['label'] ?? null;

            if (! is_string($label) || $label === '' || mb_strlen($label) > 255) {
                $errors["{$at}.label"][] = __('reports.column_label_invalid');
            }

            $kind = $column['kind'] ?? null;

            if (! is_string($kind) || ! in_array($kind, self::COLUMN_KINDS, true)) {
                $errors["{$at}.kind"][] = __('reports.column_kind_invalid', ['allowed' => implode(', ', self::COLUMN_KINDS)]);
                $kind = 'period';
            }

            $normalized = ['key' => (string) $key, 'label' => (string) $label, 'kind' => $kind];

            if ($kind === 'difference') {
                foreach (['minuend', 'subtrahend'] as $side) {
                    $ref = $column[$side] ?? null;

                    if (! is_string($ref) || ! isset($seen[$ref])) {
                        $errors["{$at}.{$side}"][] = __('reports.column_ref_unknown');
                    }

                    $normalized[$side] = (string) $ref;
                }
            } else {
                $shift = is_array($column['shift'] ?? null) ? $column['shift'] : [];
                $years = $shift['years'] ?? 0;
                $months = $shift['months'] ?? 0;

                if (! is_int($years) || ! is_int($months) || $years > 0 || $months > 0 || $years < -50 || $months < -600) {
                    $errors["{$at}.shift"][] = __('reports.shift_invalid');
                    $years = $months = 0;
                }

                $normalized['shift'] = ['years' => $years, 'months' => $months];
            }

            if (is_string($key)) {
                $seen[$key] = true;
            }

            $columns[] = $normalized;
        }

        return $columns;
    }
}
