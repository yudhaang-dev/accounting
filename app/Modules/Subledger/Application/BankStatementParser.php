<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\BankImportMapping;
use App\Modules\Subledger\Domain\CashTransaction;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reads the CSV of a bank statement with the column mapping of its cash account (SL-FR-13). The read is
 * all or nothing: every problem is reported with the line of the file where it is.
 *
 * @phpstan-type Row array{line: int, date: string, description: string|null, reference: string|null, direction: string, amount: string}
 */
final class BankStatementParser
{
    public const MAX_ROWS = 5000;

    /**
     * @return non-empty-list<Row>
     *
     * @throws ValidationException
     */
    public function parse(string $csv, BankImportMapping $mapping): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => [__('bank.csv_unreadable')]]);
        }

        fwrite($handle, $csv);
        rewind($handle);

        /** @var list<Row> $rows */
        $rows = [];
        $errors = [];
        $line = 0;

        while (($cells = fgetcsv($handle, separator: $mapping->delimiter, escape: '')) !== false) {
            $line++;

            if ($line === 1 && $mapping->has_header) {
                continue;
            }

            if ($cells === [null] || trim(implode('', array_map(fn (?string $cell): string => (string) $cell, $cells))) === '') {
                continue;
            }

            $row = $this->row($line, $cells, $mapping, $errors);

            if ($row !== null) {
                $rows[] = $row;
            }

            if (count($rows) + count($errors) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => [__('bank.csv_too_many_rows', ['max' => self::MAX_ROWS])]]);
            }
        }

        fclose($handle);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => [__('bank.csv_empty')]]);
        }

        return $rows;
    }

    /**
     * @param  list<string|null>  $cells
     * @param  array<string, array<int, array<mixed>|string>>  $errors
     *
     * @param-out array<string, array<int, array<mixed>|string>> $errors
     *
     * @return Row|null
     */
    private function row(int $line, array $cells, BankImportMapping $mapping, array &$errors): ?array
    {
        $cell = fn (?int $column): ?string => $column === null ? null : (isset($cells[$column - 1]) ? trim((string) $cells[$column - 1]) : null);
        $wanted = array_filter([$mapping->date_column, $mapping->amount_column, $mapping->money_in_column, $mapping->money_out_column]);

        if ($wanted !== [] && max($wanted) > count($cells)) {
            $errors["rows.{$line}"] = [__('bank.csv_columns_missing', ['columns' => max($wanted)])];

            return null;
        }

        $date = $this->date((string) $cell($mapping->date_column), $mapping->date_format);

        if ($date === null) {
            $errors["rows.{$line}.date"] = [__('bank.csv_date_invalid', ['value' => $cell($mapping->date_column), 'format' => $mapping->date_format])];
        }

        $money = $this->money($cell, $mapping);

        if ($money === null) {
            $errors["rows.{$line}.amount"] = [$mapping->amount_column !== null
                ? __('bank.csv_amount_invalid', ['value' => $cell($mapping->amount_column)])
                : __('bank.csv_in_out_invalid', ['in' => $cell($mapping->money_in_column), 'out' => $cell($mapping->money_out_column)])];
        }

        if ($date === null || $money === null) {
            return null;
        }

        $description = $cell($mapping->description_column);
        $reference = $cell($mapping->reference_column);

        return [
            'line' => $line,
            'date' => $date,
            'description' => $description === null || $description === '' ? null : mb_substr($description, 0, 500),
            'reference' => $reference === null || $reference === '' ? null : mb_substr($reference, 0, 255),
            'direction' => $money['direction'],
            'amount' => (string) $money['amount']->toScale(CashTransaction::AMOUNT_SCALE),
        ];
    }

    /**
     * The direction and size of the line: from the sign of one column, or from whichever of the money in and
     * money out columns holds a number. Null when the cells do not say exactly one non-zero amount.
     *
     * @param  callable(int|null): (string|null)  $cell
     * @return array{direction: string, amount: BigDecimal}|null
     */
    private function money(callable $cell, BankImportMapping $mapping): ?array
    {
        if ($mapping->amount_column !== null) {
            $signed = $this->number((string) $cell($mapping->amount_column), $mapping->decimal_separator);

            if ($signed === null || $signed->isZero()) {
                return null;
            }

            return ['direction' => $signed->isPositive() ? 'in' : 'out', 'amount' => $signed->abs()];
        }

        $in = $this->number((string) $cell($mapping->money_in_column), $mapping->decimal_separator, emptyIsZero: true);
        $out = $this->number((string) $cell($mapping->money_out_column), $mapping->decimal_separator, emptyIsZero: true);

        if ($in === null || $out === null || $in->isZero() === $out->isZero()) {
            return null;
        }

        return $in->isZero() ? ['direction' => 'out', 'amount' => $out->abs()] : ['direction' => 'in', 'amount' => $in->abs()];
    }

    private function date(string $value, string $format): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $problems = DateTimeImmutable::getLastErrors();

        if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /** A number with at most six decimals; parentheses and a leading minus make it negative. */
    private function number(string $value, string $decimalSeparator, bool $emptyIsZero = false): ?BigDecimal
    {
        $value = str_replace([' ', "\u{00A0}"], '', $value);

        if ($value === '') {
            return $emptyIsZero ? BigDecimal::zero() : null;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $value, $inside) === 1) {
            $negative = true;
            $value = $inside[1];
        }

        $value = $decimalSeparator === ','
            ? str_replace(',', '.', str_replace('.', '', $value))
            : str_replace(',', '', $value);

        if (preg_match('/^([+-]?)(\d+(?:\.\d{1,'.CashTransaction::AMOUNT_SCALE.'})?)$/', $value, $parts) !== 1) {
            return null;
        }

        $number = BigDecimal::of($parts[2]);

        return $negative !== ($parts[1] === '-') ? $number->negated() : $number;
    }
}
