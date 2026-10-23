<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Contracts\ControlRole;
use Brick\Math\BigDecimal;

/**
 * What each party owes and is owed on a date, in the functional currency (SL-FR-08). It is the total of the aging
 * report of each control role, so the figures are those of the report by construction: receivable is what the
 * company is owed, payable is what it owes, and an advance or credit note not applied lowers the side it belongs to.
 * Parties with nothing outstanding on either side are left out.
 *
 * @phpstan-type Balance array{party_id: string, receivable: string, payable: string}
 */
final class PartyBalances
{
    public function __construct(private readonly AgingReport $aging) {}

    /**
     * @return array{as_of: string, functional_currency: string, parties: list<Balance>}
     */
    public function __invoke(string $companyId, string $asOf): array
    {
        $receivables = ($this->aging)($companyId, $asOf, ControlRole::Receivables);
        $payables = ($this->aging)($companyId, $asOf, ControlRole::Payables);

        $balances = [];

        foreach (['receivable' => $receivables, 'payable' => $payables] as $side => $report) {
            foreach ($report['parties'] as $party) {
                $balances[$party['party_id']] ??= ['party_id' => $party['party_id'], 'receivable' => '0.000000', 'payable' => '0.000000'];
                $balances[$party['party_id']][$side] = $party['totals']['total'];
            }
        }

        return [
            'as_of' => $asOf,
            'functional_currency' => $receivables['functional_currency'],
            'parties' => array_values(array_filter(
                $balances,
                fn (array $balance): bool => ! BigDecimal::of($balance['receivable'])->isZero() || ! BigDecimal::of($balance['payable'])->isZero(),
            )),
        ];
    }
}
