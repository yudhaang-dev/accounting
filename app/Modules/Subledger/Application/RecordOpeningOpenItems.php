<?php

namespace App\Modules\Subledger\Application;

use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Contracts\DocumentKind;
use App\Modules\Subledger\Contracts\OpeningOpenItem;
use App\Modules\Subledger\Contracts\OpeningOpenItemBatch;
use App\Modules\Subledger\Contracts\OpeningOpenItems;
use App\Modules\Subledger\Contracts\PartyDirectory;
use App\Modules\Subledger\Domain\Document;
use App\Modules\Subledger\Domain\OpenItem;
use App\Modules\Subledger\Domain\PartyExternalRef;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open items of the conversion date (SL-FR-10). Each is recorded as a document of the opening balance
 * journal, which is not posted through the subledger: the ledger posts the batch, holding the control accounts
 * to the totals worked out here, and the items are recorded after it. An item that raises the balance of a
 * control account is an invoice; one that lowers it, such as a credit note or an advance not applied yet, is a
 * credit note, which settles nothing after the conversion until it is allocated (SL-FR-05).
 *
 * @phpstan-type Planned array{item: OpeningOpenItem, party: string, account: string, side: string, currency: string, rate: string, amount: BigDecimal, functional: BigDecimal}
 */
final class RecordOpeningOpenItems implements OpeningOpenItems
{
    public function __construct(
        private readonly PartyDirectory $parties,
        private readonly ControlAccounts $controlAccounts,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
        private readonly CompanyContext $company,
        private readonly Audit $audit,
    ) {}

    public function totals(OpeningOpenItemBatch $batch): array
    {
        return $this->company->run($batch->companyId, function () use ($batch): array {
            $totals = [];

            foreach ($this->plan($batch) as $row) {
                $signed = $row['side'] === 'debit' ? $row['functional'] : $row['functional']->negated();
                $totals[$row['account']] = ($totals[$row['account']] ?? BigDecimal::zero())->plus($signed);
            }

            return array_map(fn (BigDecimal $total): string => (string) $total->toScale(6), $totals);
        });
    }

    public function record(OpeningOpenItemBatch $batch, string $journalId): int
    {
        return $this->company->run($batch->companyId, fn (): int => DB::transaction(function () use ($batch, $journalId): int {
            $plan = $this->plan($batch);

            foreach ($plan as $row) {
                $item = $row['item'];
                $dueDate = $item->dueDate ?? $item->documentDate;

                $document = Document::create([
                    'kind' => $item->kind,
                    'control_role' => $item->role,
                    'party_id' => $row['party'],
                    'journal_id' => $journalId,
                    'document_number' => $item->documentNumber,
                    'document_date' => $item->documentDate,
                    'due_date' => $dueDate,
                    'accounting_date' => $batch->ledgerStartDate,
                    'currency' => $row['currency'],
                    'amount' => $row['amount'],
                    'functional_amount' => $row['functional'],
                    'exchange_rate' => $row['rate'],
                    'description' => (string) __('documents.opening_open_item', ['number' => $item->documentNumber]),
                    'is_opening' => true,
                    'control_account_id' => $row['account'],
                    'created_by' => $batch->importedBy,
                ]);

                OpenItem::create([
                    'document_id' => $document->id,
                    'party_id' => $row['party'],
                    'control_role' => $item->role,
                    'kind' => $item->kind,
                    'side' => $row['side'],
                    'document_number' => $item->documentNumber,
                    'document_date' => $item->documentDate,
                    'due_date' => $dueDate,
                    'currency' => $row['currency'],
                    'original_amount' => $row['amount'],
                    'remaining_amount' => $row['amount'],
                    'original_functional' => $row['functional'],
                    'remaining_functional' => $row['functional'],
                    'exchange_rate' => $row['rate'],
                ]);
            }

            $this->audit->record(
                'open_items.opening_imported',
                'Opening open items of '.$batch->ledgerStartDate.' recorded with journal '.$journalId.'.',
                properties: ['journal_id' => $journalId, 'items' => count($plan)],
                companyId: $batch->companyId,
            );

            return count($plan);
        }));
    }

    /**
     * @return list<Planned>
     *
     * @throws ValidationException
     */
    private function plan(OpeningOpenItemBatch $batch): array
    {
        $companyId = $batch->companyId;
        $functional = $this->companies->find($companyId)->functionalCurrency ?? '';
        $scale = $this->currencies->functionalScale($companyId);
        $mode = ($this->currencies->find($companyId, $functional)->rounding ?? CurrencyRounding::HalfUp)->brick();
        $errors = [];
        $planned = [];
        $seen = [];

        foreach ($batch->items as $index => $item) {
            $field = fn (string $name): string => "open_items.{$index}.{$name}";
            $before = array_sum(array_map('count', $errors));

            if (! in_array($item->kind, [DocumentKind::Invoice, DocumentKind::CreditNote], true)) {
                $errors[$field('kind')][] = __('documents.opening_kind');
            }

            $partyId = $this->party($batch, $item);
            $party = $partyId === null ? null : $this->parties->find($companyId, $partyId);

            if ($party === null) {
                $errors[$field('party')][] = __('documents.opening_party_unknown');
            } elseif (! $party->isActive) {
                $errors[$field('party')][] = __('documents.party_inactive', ['name' => $party->name]);
            } elseif (! ($item->role === ControlRole::Receivables ? $party->isCustomer : $party->isSupplier)) {
                $errors[$field('party')][] = __($item->role === ControlRole::Receivables ? 'documents.party_not_customer' : 'documents.party_not_supplier', ['name' => $party->name]);
            }

            $key = ($partyId ?? '').'|'.$item->role->value.'|'.$item->documentNumber;

            if (isset($seen[$key])) {
                $errors[$field('document_number')][] = __('documents.opening_duplicate', ['number' => $item->documentNumber]);
            }

            $seen[$key] = true;

            if ($item->documentDate > $batch->ledgerStartDate) {
                $errors[$field('document_date')][] = __('documents.opening_after_start', ['date' => $batch->ledgerStartDate]);
            }

            if ($item->dueDate !== null && $item->dueDate < $item->documentDate) {
                $errors[$field('due_date')][] = __('documents.due_before_document');
            }

            $currency = $item->currency ?? $functional;
            $rate = '1';

            if ($currency !== $functional) {
                if (! $this->currencies->isActive($companyId, $currency)) {
                    $errors[$field('currency')][] = __('documents.opening_currency_inactive', ['currency' => $currency]);
                }

                if ($item->exchangeRate === null || ! BigDecimal::of($item->exchangeRate)->isPositive()) {
                    $errors[$field('exchange_rate')][] = __('documents.opening_rate_required', ['currency' => $currency]);
                } else {
                    $rate = $item->exchangeRate;
                }
            }

            $amount = BigDecimal::of($item->amount);

            if (! $amount->isPositive()) {
                $errors[$field('amount')][] = __('documents.allocation_amount_positive');
            }

            $converted = $amount->multipliedBy($rate)->toScale($scale, $mode);

            if (! $converted->isPositive() && ! isset($errors[$field('amount')])) {
                $errors[$field('amount')][] = __('documents.allocation_amount_positive');
            }

            if (array_sum(array_map('count', $errors)) > $before) {
                continue;
            }

            $planned[] = [
                'item' => $item,
                'party' => (string) $partyId,
                'account' => $this->controlAccounts->require($companyId, $item->role, $partyId, $batch->ledgerStartDate)->accountId,
                'side' => $item->kind->controlSide($item->role),
                'currency' => $currency,
                'rate' => $rate,
                'amount' => $amount,
                'functional' => $converted,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $planned;
    }

    private function party(OpeningOpenItemBatch $batch, OpeningOpenItem $item): ?string
    {
        if ($item->partyId !== null) {
            return $item->partyId;
        }

        if ($item->partyRef === null || $batch->sourceSystem === null) {
            return null;
        }

        return PartyExternalRef::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $batch->companyId)->where('source_system', $batch->sourceSystem)->where('external_ref', $item->partyRef)
            ->value('party_id');
    }
}
