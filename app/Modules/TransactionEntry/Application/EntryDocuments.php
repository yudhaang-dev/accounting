<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\Subledger\Contracts\PostedDocument;
use App\Modules\Subledger\Contracts\SubledgerDocument;
use App\Modules\Subledger\Contracts\SubledgerPosting;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\SubledgerRoute;
use Illuminate\Validation\ValidationException;

/**
 * The entries whose version has a subledger route go to the subledger instead of straight to the ledger
 * (TE-FR-10): they record a document of the party in their fields and post its journal through the
 * subledger. This class turns such an entry into the document, and the errors the subledger finds back
 * into errors on the fields of the entry.
 *
 * @phpstan-import-type Route from SubledgerRoute
 */
final class EntryDocuments
{
    public function __construct(private readonly SubledgerPosting $subledger) {}

    /**
     * The route of the version of the entry, or null when the entry goes straight to the ledger. A
     * reversal never has one: reversing the journal of a document is not an entry of its own type.
     *
     * @return Route|null
     */
    public function route(Entry $entry): ?array
    {
        return $entry->reverses_journal_id === null ? $entry->typeVersion()->subledger_route : null;
    }

    /**
     * @param  Route  $route
     * @param  PostJournal  $journal  the journal of the entry, with the source `subledger`
     *
     * @throws ValidationException
     */
    public function validate(Entry $entry, array $route, PostJournal $journal): void
    {
        try {
            $this->subledger->validate($this->document($entry, $route, $journal));
        } catch (ValidationException $e) {
            throw $this->onFields($e, $route);
        }
    }

    /**
     * @param  Route  $route
     * @param  PostJournal  $journal  the journal of the entry, with the source `subledger`
     *
     * @throws ValidationException
     */
    public function post(Entry $entry, array $route, PostJournal $journal): PostedDocument
    {
        try {
            return $this->subledger->post($this->document($entry, $route, $journal));
        } catch (ValidationException $e) {
            throw $this->onFields($e, $route);
        }
    }

    /**
     * @param  Route  $route
     */
    private function document(Entry $entry, array $route, PostJournal $journal): SubledgerDocument
    {
        $values = $entry->field_values ?? [];

        return new SubledgerDocument(
            kind: SubledgerRoute::kind($route),
            role: SubledgerRoute::role($route),
            partyId: $this->value($values, $route['party_field']) ?? '',
            journal: $journal,
            cashAccountId: $this->value($values, $route['cash_account_field']),
            documentNumber: $this->value($values, $route['document_number_field']) ?? $entry->number,
            dueDate: $this->value($values, $route['due_date_field']),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function value(array $values, ?string $field): ?string
    {
        $value = $field === null ? null : ($values[$field] ?? null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * What the subledger says about the party, the cash account and the due date is said about the field
     * of the entry that holds it.
     *
     * @param  Route  $route
     */
    private function onFields(ValidationException $e, array $route): ValidationException
    {
        $fields = [
            'party_id' => "values.{$route['party_field']}",
            'cash_account_id' => 'values.'.($route['cash_account_field'] ?? 'cash_account_id'),
            'due_date' => 'values.'.($route['due_date_field'] ?? 'due_date'),
        ];
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $errors[$fields[$key] ?? $key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
