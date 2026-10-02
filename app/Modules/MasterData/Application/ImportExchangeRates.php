<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\RateSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports rates from CSV text (MD-FR-07). The first line is a header with the columns `rate_type`,
 * `currency`, `effective_date` and `rate`, in any order, separated by commas. The import is all or
 * nothing: if any row is invalid nothing is recorded and every problem is reported with its line.
 */
final class ImportExchangeRates
{
    public const MAX_ROWS = 1000;

    private const COLUMNS = ['rate_type', 'currency', 'effective_date', 'rate'];

    public function __construct(private readonly RecordExchangeRate $record) {}

    /**
     * @return array{created: int, unchanged: int}
     *
     * @throws ValidationException
     */
    public function __invoke(string $csv): array
    {
        $rows = $this->parse($csv);
        $created = 0;
        $unchanged = 0;
        $errors = [];

        DB::transaction(function () use ($rows, &$created, &$unchanged, &$errors): void {
            $seen = [];

            foreach ($rows as $line => $row) {
                $key = implode('|', [$row['rate_type'], $row['currency'], $row['effective_date']]);

                if (isset($seen[$key])) {
                    $errors["rows.{$line}"] = [__('rates.csv_duplicate', ['line' => $seen[$key]])];

                    continue;
                }

                $seen[$key] = $line;

                try {
                    $result = ($this->record)($row, RateSource::Csv);
                    $result['created'] ? $created++ : $unchanged++;
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $errors["rows.{$line}.{$field}"] = $messages;
                    }
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        });

        return ['created' => $created, 'unchanged' => $unchanged];
    }

    /**
     * @return array<int, array{rate_type: string, currency: string, effective_date: string, rate: string}> keyed by CSV line number
     *
     * @throws ValidationException
     */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];

        $header = array_map(fn (?string $name): string => strtolower(trim((string) $name)), str_getcsv((string) array_shift($lines), escape: ''));

        if (array_diff(self::COLUMNS, $header) !== []) {
            throw ValidationException::withMessages(['file' => [__('rates.csv_header', ['columns' => implode(', ', self::COLUMNS)])]]);
        }

        $rows = [];

        foreach ($lines as $index => $text) {
            if (trim($text) === '') {
                continue;
            }

            $cells = str_getcsv($text, escape: '');
            $cell = fn (string $column): string => trim((string) ($cells[array_search($column, $header, true)] ?? ''));

            $rows[$index + 2] = [
                'rate_type' => $cell('rate_type'),
                'currency' => $cell('currency'),
                'effective_date' => $cell('effective_date'),
                'rate' => $cell('rate'),
            ];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => [__('rates.csv_empty')]]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => [__('rates.csv_too_many', ['max' => self::MAX_ROWS])]]);
        }

        return $rows;
    }
}
