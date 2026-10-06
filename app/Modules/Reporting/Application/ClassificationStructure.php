<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountData;
use Illuminate\Validation\ValidationException;

/**
 * The shape of a movement classification version (RP-FR-03): the rows that movement is classified
 * into and the rules that send it there. A rule matches a line by its account (a selector list, as
 * in report rows) and, when it names transaction type keys, by the key of the journal; the first
 * rule that matches wins. Movement that no rule matches goes to the `unclassified` row.
 */
final class ClassificationStructure
{
    /** Key of the row for movement that no rule matches. */
    public const UNCLASSIFIED = 'unclassified';

    private const MAX_ROWS = 100;

    private const MAX_RULES = 500;

    private const ROW_KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    private const TYPE_KEY = '/^[a-z][a-z0-9_.]*$/';

    /**
     * @param  array<string, mixed>  $input
     * @param  list<AccountData>  $accounts  the chart of the company
     * @return array{rows: list<array{key: string, label: string}>, rules: list<array{row: string, accounts: list<array<string, string>>, transaction_type_keys: list<string>}>}
     *
     * @throws ValidationException
     */
    public static function normalize(array $input, array $accounts): array
    {
        $errors = [];
        $rows = self::rows($input['rows'] ?? null, $errors);
        $rules = self::rules($input['rules'] ?? null, $rows, $accounts, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['rows' => $rows, 'rules' => $rules];
    }

    /**
     * @param  array<string, list<array<mixed>|string>>  $errors
     * @return list<array{key: string, label: string}>
     */
    private static function rows(mixed $input, array &$errors): array
    {
        if (! is_array($input) || $input === [] || count($input) > self::MAX_ROWS) {
            $errors['structure.rows'][] = __('reports.classification_rows_count', ['max' => self::MAX_ROWS]);

            return [];
        }

        $rows = [];
        $seen = [];

        foreach (array_values($input) as $index => $row) {
            $row = is_array($row) ? $row : [];
            $key = $row['key'] ?? null;
            $label = $row['label'] ?? null;

            if (! is_string($key) || preg_match(self::ROW_KEY, $key) !== 1 || $key === self::UNCLASSIFIED) {
                $errors["structure.rows.{$index}.key"][] = __('reports.classification_row_key_invalid');
            } elseif (isset($seen[$key])) {
                $errors["structure.rows.{$index}.key"][] = __('reports.row_key_repeated', ['key' => $key]);
            }

            if (! is_string($label) || $label === '' || mb_strlen($label) > 255) {
                $errors["structure.rows.{$index}.label"][] = __('reports.row_label_invalid');
            }

            if (is_string($key)) {
                $seen[$key] = true;
            }

            $rows[] = ['key' => (string) $key, 'label' => (string) $label];
        }

        return $rows;
    }

    /**
     * @param  list<array{key: string, label: string}>  $rows
     * @param  list<AccountData>  $accounts
     * @param  array<string, list<array<mixed>|string>>  $errors
     * @return list<array{row: string, accounts: list<array<string, string>>, transaction_type_keys: list<string>}>
     */
    private static function rules(mixed $input, array $rows, array $accounts, array &$errors): array
    {
        if (! is_array($input) || $input === [] || count($input) > self::MAX_RULES) {
            $errors['structure.rules'][] = __('reports.classification_rules_count', ['max' => self::MAX_RULES]);

            return [];
        }

        $rowKeys = array_column($rows, 'key');
        $byId = [];

        foreach ($accounts as $account) {
            $byId[$account->id] = $account;
        }

        $rules = [];

        foreach (array_values($input) as $index => $rule) {
            $at = "structure.rules.{$index}";
            $rule = is_array($rule) ? $rule : [];
            $row = $rule['row'] ?? null;

            if (! is_string($row) || ! in_array($row, $rowKeys, true)) {
                $errors["{$at}.row"][] = __('reports.classification_rule_row_unknown');
            }

            $keys = $rule['transaction_type_keys'] ?? [];

            if (! is_array($keys) || count($keys) > 50 || array_filter($keys, fn (mixed $key): bool => ! is_string($key) || preg_match(self::TYPE_KEY, $key) !== 1 || strlen($key) > 64) !== []) {
                $errors["{$at}.transaction_type_keys"][] = __('reports.classification_type_keys_invalid');
                $keys = [];
            }

            $rules[] = [
                'row' => (string) $row,
                'accounts' => ReportStructure::selectors($rule['accounts'] ?? null, $at, $byId, $errors),
                'transaction_type_keys' => array_values(array_unique($keys)),
            ];
        }

        return $rules;
    }
}
