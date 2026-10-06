<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\OpeningBalanceBatchData;
use App\Modules\GeneralLedger\Contracts\OpeningBalances;

/**
 * Which part of a date range the ledger can answer for (RP-FR-13). A company that started in the
 * system has all of it. One that migrated has nothing before the ledger start date; between that date
 * and the cutover date it has what the history import brought, in its granularity, and from the
 * cutover date on the journals themselves. What the ledger does not hold is reported as `partial` or
 * `unavailable`, never as zero (RP-AC-10).
 */
final class HistoryCoverage
{
    public const COMPLETE = 'complete';

    public const PARTIAL = 'partial';

    public const UNAVAILABLE = 'unavailable';

    private const TRANSACTIONS = 'transactions';

    /** @var array<string, OpeningBalanceBatchData|null> */
    private array $batches = [];

    public function __construct(private readonly OpeningBalances $openingBalances) {}

    /**
     * `$cumulative` is for balances that carry what came before them (as of and year to date columns):
     * the imported balances hold that, so only the end of the range has to be inside the data.
     * `$classified` is for reports that need the Jenis Transaksi of each journal (direct cash flow):
     * a summary per period does not have it, so before the cutover date they are partial at best.
     *
     * @return array{coverage_from: string|null, coverage_to: string|null, coverage_granularity: string, coverage_status: string}
     */
    public function assess(string $companyId, string $from, string $to, bool $cumulative = false, bool $classified = false): array
    {
        $batch = array_key_exists($companyId, $this->batches) ? $this->batches[$companyId] : ($this->batches[$companyId] = $this->openingBalances->current($companyId));

        if ($batch === null) {
            return self::result($from, $to, self::TRANSACTIONS, self::COMPLETE);
        }

        $granularity = $batch->historyGranularity ?? self::TRANSACTIONS;
        $available = $classified && $granularity !== self::TRANSACTIONS ? $batch->cutoverDate : $batch->ledgerStartDate;

        if ($to < $available) {
            return self::result(null, null, $granularity, self::UNAVAILABLE);
        }

        $start = $cumulative ? $from : max($from, $available);
        $status = ($cumulative ? $to : $from) < $available ? self::PARTIAL : self::COMPLETE;

        return self::result($start, $to, $start < $batch->cutoverDate ? $granularity : self::TRANSACTIONS, $status);
    }

    /** The weakest of the statuses: a report is as complete as its least complete part. */
    public static function combine(string ...$statuses): string
    {
        return count(array_unique($statuses)) === 1 ? $statuses[0] : ($statuses === [] ? self::COMPLETE : self::PARTIAL);
    }

    /**
     * The coverage of a report from the coverage of its columns.
     *
     * @param  list<array{coverage_from: string|null, coverage_to: string|null, coverage_granularity: string, coverage_status: string}>  $parts
     * @return array{coverage_from: string|null, coverage_to: string|null, coverage_granularity: string, coverage_status: string}
     */
    public static function merge(array $parts): array
    {
        $froms = array_values(array_filter(array_column($parts, 'coverage_from')));
        $tos = array_values(array_filter(array_column($parts, 'coverage_to')));
        $granularities = array_values(array_filter(array_column($parts, 'coverage_granularity'), fn (string $g): bool => $g !== self::TRANSACTIONS));

        return self::result(
            $froms === [] ? null : min($froms),
            $tos === [] ? null : max($tos),
            $granularities[0] ?? self::TRANSACTIONS,
            self::combine(...array_column($parts, 'coverage_status')),
        );
    }

    /** @return array{coverage_from: string|null, coverage_to: string|null, coverage_granularity: string, coverage_status: string} */
    private static function result(?string $from, ?string $to, string $granularity, string $status): array
    {
        return ['coverage_from' => $from, 'coverage_to' => $to, 'coverage_granularity' => $granularity, 'coverage_status' => $status];
    }
}
