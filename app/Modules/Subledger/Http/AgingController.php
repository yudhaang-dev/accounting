<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Application\AgingReport;
use App\Modules\Subledger\Application\ManageAgingPolicy;
use App\Modules\Subledger\Contracts\ControlRole;
use App\Platform\Export\CsvExport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AgingController
{
    /**
     * Receivables (`control_role=receivables_control`) or payables (`payables_control`) by age on `as_of`, which may
     * be a past date: only allocations dated up to it count (SL-AC-04). Amounts are per party and document, in the
     * transaction currency and in the functional one, and positive when they raise the balance of the control
     * account (invoices) and negative when they lower it (credit notes, advances not applied). The buckets and the
     * date the age counts from come from the aging policy; `basis` (`due_date` or `document_date`) overrides the
     * date for this report. `party_id` narrows it to one party. Drill down with `open_item_id` and `document_id`
     * of each item. With `Accept: text/csv` the same report is downloaded, under the same permission (X-FR-02).
     *
     * @response array{data: array{parameters: array{as_of: string, control_role: string, party_id: string|null, basis: string, buckets: list<array{key: string, from_days: int|null, to_days: int|null}>}, functional_currency: string, totals: array<string, string>, currency_totals: list<array{currency: string, buckets: array<string, string>, total: string}>, parties: list<array{party_id: string, party_name: string, totals: array<string, string>, items: list<array{open_item_id: string, document_id: string, document_number: string, kind: string, document_date: string, due_date: string, currency: string, remaining_amount: string, remaining_functional: string, age_days: int, bucket: string}>}>}}
     */
    public function show(AgingReportRequest $request, AgingReport $report, string $companyId): JsonResponse|Response
    {
        $result = $report(
            $companyId,
            (string) $request->validated('as_of'),
            ControlRole::from((string) $request->validated('control_role')),
            $request->validated('party_id'),
            $request->validated('basis'),
        );

        if (! str_contains((string) $request->header('Accept'), 'text/csv')) {
            return new JsonResponse(['data' => $result]);
        }

        $rows = [];

        foreach ($result['parties'] as $party) {
            foreach ($party['items'] as $item) {
                $rows[] = [$party['party_name'], $item['document_number'], $item['kind'], $item['document_date'], $item['due_date'], $item['currency'], $item['remaining_amount'], $item['remaining_functional'], $item['age_days'], $item['bucket']];
            }
        }

        $rows[] = ['Total', '', '', '', '', '', '', $result['totals']['total'], '', ''];

        return CsvExport::download(
            "aging_{$result['parameters']['control_role']}_{$result['parameters']['as_of']}.csv",
            ['party', 'document_number', 'kind', 'document_date', 'due_date', 'currency', 'amount', 'functional_amount', 'age_days', 'bucket'],
            $rows,
        );
    }

    /**
     * The buckets and the date aging counts from (SL-FR-08). A company that never set a policy reads the defaults
     * with `lock_version` 0, the version its first change is made from.
     */
    public function showPolicy(ManageAgingPolicy $policies): JsonResponse
    {
        return new JsonResponse(['data' => $policies->current()]);
    }

    /**
     * Sets `basis` and `buckets`, the upper bound in days of each bucket after the current one, ascending. The
     * change applies to every aging report from now on and alters no stored amount. `lock_version` is required.
     */
    public function updatePolicy(UpdateAgingPolicyRequest $request, ManageAgingPolicy $policies): JsonResponse
    {
        /** @var array{lock_version: int, basis: string, buckets: list<int>} $data */
        $data = $request->validated();

        return new JsonResponse(['data' => $policies->update(['basis' => $data['basis'], 'buckets' => array_map('intval', $data['buckets'])], $data['lock_version'])]);
    }
}
