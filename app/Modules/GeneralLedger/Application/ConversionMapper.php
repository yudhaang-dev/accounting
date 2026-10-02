<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceLine;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * What the opening balance and the history import share (GL-FR-11): translating the codes of the
 * source system into accounts and dimension values of the company, and holding control accounts to
 * the totals of the subledger.
 */
final class ConversionMapper
{
    public function __construct(private readonly AccountDirectory $accounts) {}

    /**
     * @param  array<string, string>  $accountMapping  source account code => account code of the company
     * @param  array<string, array<string, string>>  $dimensionMapping  dimension type key => source value code => dimension value ID
     * @param  list<OpeningBalanceLine>  $lines
     * @param  string  $field  error key of the lines, e.g. `lines` or `journals.2.lines`
     * @return array{lines: list<PostJournalLine>, account_ids: list<string>}
     *
     * @throws ApiException MAPPING_REQUIRED
     * @throws ValidationException
     */
    public function translate(string $companyId, array $accountMapping, array $dimensionMapping, array $lines, string $field = 'lines'): array
    {
        $unmapped = [];
        $unknown = [];

        /** @var array<string, string> $accountIds source code => account ID */
        $accountIds = [];
        $byCode = Account::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereIn('code', array_values(array_unique(array_values($accountMapping))))->pluck('id', 'code');

        foreach ($lines as $index => $line) {
            if (! array_key_exists($line->sourceAccountCode, $accountMapping)) {
                $unmapped["{$field}.{$index}.source_account_code"] = [__('ledger.opening_account_unmapped', ['code' => $line->sourceAccountCode])];

                continue;
            }

            $target = $accountMapping[$line->sourceAccountCode];

            if (! isset($byCode[$target])) {
                $unknown["{$field}.{$index}.source_account_code"] = [__('ledger.opening_account_unknown', ['source' => $line->sourceAccountCode, 'code' => $target])];

                continue;
            }

            $accountIds[$line->sourceAccountCode] = $byCode[$target];

            foreach ($line->dimensions ?? [] as $type => $sourceCode) {
                if (! isset($dimensionMapping[$type][$sourceCode])) {
                    $unmapped["{$field}.{$index}.dimensions.{$type}"] = [__('ledger.opening_dimension_unmapped', ['code' => $sourceCode, 'type' => $type])];
                }
            }
        }

        if ($unmapped !== [] || $unknown !== []) {
            throw new ApiException(ErrorCode::MappingRequired, errors: $unmapped + $unknown);
        }

        $posted = [];
        $seen = [];
        $duplicates = [];
        $used = [];

        foreach ($lines as $index => $line) {
            $dimensions = [];

            foreach ($line->dimensions ?? [] as $type => $sourceCode) {
                $dimensions[$type] = $dimensionMapping[$type][$sourceCode];
            }

            ksort($dimensions);
            $accountId = $accountIds[$line->sourceAccountCode];
            $key = json_encode([$accountId, $line->currency, $dimensions], JSON_THROW_ON_ERROR);

            if (isset($seen[$key])) {
                $duplicates["{$field}.{$index}.source_account_code"] = [__('ledger.opening_duplicate_line', ['code' => $line->sourceAccountCode, 'first' => $seen[$key] + 1])];
            }

            $seen[$key] ??= $index;
            $posted[] = new PostJournalLine(
                $accountId,
                $line->debit,
                $line->credit,
                $line->description,
                $line->currency,
                dimensions: $dimensions === [] ? null : $dimensions,
                exchangeRate: $line->exchangeRate,
            );
            $used[$accountId] = true;
        }

        if ($duplicates !== []) {
            throw ValidationException::withMessages($duplicates);
        }

        return ['lines' => $posted, 'account_ids' => array_keys($used)];
    }

    /**
     * The same content gives the same hash, whatever the order of the keys of its objects.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        $sort = function (array &$value) use (&$sort): void {
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }

            if (! array_is_list($value)) {
                ksort($value);
            }
        };
        $sort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * The control accounts among the accounts of the batch and the accounts the subledger reports.
     * An account that takes neither entries nor system postings is kept by a subledger.
     *
     * @param  list<string>  $accountIds
     * @param  array<string, string>  $totals  control account ID => net debit minus credit of its open items
     * @return array<string, AccountData>
     */
    public function controlAccounts(string $companyId, array $accountIds, array $totals): array
    {
        $control = [];

        foreach (array_unique([...$accountIds, ...array_keys($totals)]) as $accountId) {
            $account = $this->accounts->find($companyId, $accountId);

            if ($account !== null && ! $account->acceptsSource(JournalSource::Entry) && ! $account->acceptsSource(JournalSource::System)) {
                $control[$accountId] = $account;
            }
        }

        return $control;
    }

    /**
     * Holds each control account to the total of its open items. An account of the batch without a
     * total is refused: nothing says what the subledger holds.
     *
     * @param  array<string, AccountData>  $control  from `controlAccounts()`
     * @param  list<string>  $accountIds  accounts of the batch
     * @param  array<string, string>  $totals
     * @param  array<string, string>  $balances  what the ledger carries per account ID, as net debit minus credit
     *
     * @throws ValidationException
     */
    public function verifyControl(array $control, array $accountIds, array $totals, array $balances): void
    {
        $errors = [];

        foreach ($control as $accountId => $account) {
            if (! isset($totals[$accountId])) {
                if (in_array($accountId, $accountIds, true)) {
                    $errors['lines'][] = __('ledger.opening_control_total_missing', ['account' => $account->code]);
                }

                continue;
            }

            $actual = BigDecimal::of($balances[$accountId] ?? '0');

            if (! $actual->isEqualTo(BigDecimal::of($totals[$accountId]))) {
                $errors['lines'][] = __('ledger.opening_control_total_differs', [
                    'account' => $account->code,
                    'balance' => $actual->toScale(2)->toString(),
                    'open_items' => BigDecimal::of($totals[$accountId])->toScale(2)->toString(),
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
