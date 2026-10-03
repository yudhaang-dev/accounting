<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Contracts\FinancialEvent;

/**
 * The business content of a financial event in one form (TE-FR-13): the order of keys and the format of
 * decimals do not change it, a dimension without a value is the same as one that is not named. What the
 * source system sends twice is then the same payload, and what it changes is not.
 */
final class CanonicalEventPayload
{
    public static function hash(FinancialEvent $event): string
    {
        return hash('sha256', json_encode(self::of($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(FinancialEvent $event): array
    {
        return self::sorted([
            'transaction_type' => ['key' => $event->transactionTypeKey, 'version' => $event->transactionTypeVersion],
            'accounting_date' => $event->accountingDate,
            'currency' => $event->currency,
            'exchange_rate' => self::decimal($event->exchangeRate),
            'amounts' => array_map(self::decimal(...), $event->amounts),
            'dimensions' => array_filter($event->dimensions, fn (mixed $value): bool => $value !== null),
            'references' => $event->references,
            'links' => $event->links,
        ]);
    }

    /** `10.50`, `10.5` and `0010.5` are one amount; whatever is not a decimal string is left for validation. */
    private static function decimal(mixed $value): mixed
    {
        if (! is_string($value) || preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return $value;
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $number = $fraction === '' ? $whole : "{$whole}.{$fraction}";

        return str_starts_with($value, '-') && $number !== '0' ? "-{$number}" : $number;
    }

    /** Sorts the keys of every map at every depth; a list keeps its order, which is part of its meaning. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
