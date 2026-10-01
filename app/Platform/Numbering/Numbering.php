<?php

namespace App\Platform\Numbering;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Patterned, gapless number series (GL-FR-05, TE-FR-16).
 *
 * Pattern tokens: {yyyy} {yy} {mm} {dd} {seq}; any other text is a literal prefix or separator,
 * for example "JU/{yyyy}/{mm}/{seq}". Series are data per company (P-01); callers pass a series key.
 */
final class Numbering
{
    private const TOKEN = '/\{([a-z]+)\}/';

    private const DATE_TOKENS = ['yyyy', 'yy', 'mm', 'dd'];

    /** Creates or updates a series definition. Changing the pattern does not alter issued numbers. */
    public function define(string $companyId, string $key, string $pattern, int $padding, ResetPeriod $reset): NumberSeries
    {
        $this->validate($pattern, $padding, $reset);

        return NumberSeries::updateOrCreate(
            ['company_id' => $companyId, 'key' => $key],
            ['pattern' => $pattern, 'padding' => $padding, 'reset_period' => $reset],
        );
    }

    /**
     * Issues the next number. Must run inside the caller's transaction: the counter row stays locked
     * until commit and the increment rolls back with the transaction, so no number is ever skipped.
     */
    public function next(string $companyId, string $key, CarbonInterface $date): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Numbering::next() must be called inside a database transaction.');
        }

        $series = NumberSeries::where('company_id', $companyId)->where('key', $key)->first()
            ?? throw new NumberSeriesNotFound($companyId, $key);

        $bucket = $series->reset_period->bucket($date);

        DB::table('pf_number_counters')->insertOrIgnore(['series_id' => $series->id, 'bucket' => $bucket, 'last_value' => 0]);

        $current = DB::table('pf_number_counters')
            ->where('series_id', $series->id)
            ->where('bucket', $bucket)
            ->lockForUpdate()
            ->value('last_value');

        $next = (int) $current + 1;

        DB::table('pf_number_counters')->where('series_id', $series->id)->where('bucket', $bucket)->update(['last_value' => $next]);

        return $this->render($series->pattern, $series->padding, $date, $next);
    }

    private function render(string $pattern, int $padding, CarbonInterface $date, int $sequence): string
    {
        return (string) preg_replace_callback(self::TOKEN, fn (array $m) => match ($m[1]) {
            'yyyy' => $date->format('Y'),
            'yy' => $date->format('y'),
            'mm' => $date->format('m'),
            'dd' => $date->format('d'),
            'seq' => str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT),
            default => $m[0],
        }, $pattern);
    }

    private function validate(string $pattern, int $padding, ResetPeriod $reset): void
    {
        if ($padding < 1 || $padding > 18) {
            throw new InvalidArgumentException('Padding must be between 1 and 18.');
        }

        preg_match_all(self::TOKEN, $pattern, $matches);
        $tokens = $matches[1];

        $unknown = array_diff($tokens, [...self::DATE_TOKENS, 'seq']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown pattern token: {'.reset($unknown).'}.');
        }

        if ((array_count_values($tokens)['seq'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Pattern must contain {seq} exactly once.');
        }

        foreach ($reset->requiredTokens() as $alternatives) {
            if (array_intersect($alternatives, $tokens) === []) {
                throw new InvalidArgumentException('Pattern needs {'.$alternatives[0].'} for a '.$reset->value.' reset, otherwise numbers repeat.');
            }
        }
    }
}
