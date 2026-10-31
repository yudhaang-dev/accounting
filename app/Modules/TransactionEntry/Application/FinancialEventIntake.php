<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Contracts\FinancialEvent;
use App\Modules\TransactionEntry\Contracts\FinancialEventChange;
use App\Modules\TransactionEntry\Contracts\FinancialEventChanges;
use App\Modules\TransactionEntry\Contracts\FinancialEventResult;
use App\Modules\TransactionEntry\Contracts\FinancialEvents;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\EntryDecision;
use App\Modules\TransactionEntry\Domain\EntryStatus;
use App\Modules\TransactionEntry\Domain\FieldType;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a financial event into an entry and submits it (TE-FR-12). The event names the fields of the
 * version by kind: `amounts` fill the amount fields, `dimensions` the dimension fields by their type key,
 * `references` the date, text and reference fields by name, and `party`, `cash_account` and `allocations`
 * the fields of those types. Nothing is computed from the values (P-02). The entry then takes the same
 * validation and approval as one entered by hand, with the client as the one who submitted it. The source key
 * makes it idempotent (TE-FR-13): the database lets one entry hold it, and a resend is compared by the hash
 * of the canonical payload.
 */
final class FinancialEventIntake implements FinancialEvents
{
    public function __construct(
        private readonly EntryDrafts $drafts,
        private readonly EntryWorkflow $workflow,
    ) {}

    public function receive(FinancialEvent $event): FinancialEventResult
    {
        $hash = CanonicalEventPayload::hash($event);

        // A resend is answered before anything is validated: the period may have closed since, and the
        // original result stands.
        $existing = $this->sourceKey($event)->first();

        if ($existing === null) {
            try {
                return $this->record($event, $hash);
            } catch (UniqueConstraintViolationException $e) {
                // Another request with the same key got in first and its entry is committed by now.
                $existing = $this->sourceKey($event)->first() ?? throw $e;
            }
        }

        return $this->replay($existing, $hash);
    }

    /**
     * @return Builder<Entry>
     */
    private function sourceKey(FinancialEvent $event): Builder
    {
        return Entry::query()
            ->where('source', 'integration')
            ->where('source_system', $event->sourceSystem)
            ->where('source_transaction_id', (string) ($event->references['source_transaction_id'] ?? ''));
    }

    private function replay(Entry $entry, string $hash): FinancialEventResult
    {
        if ($entry->source_payload_hash !== $hash) {
            throw new ApiException(ErrorCode::SourceTransactionConflict);
        }

        return $this->result($entry, replayed: true);
    }

    private function record(FinancialEvent $event, string $hash): FinancialEventResult
    {
        $fields = [];

        try {
            $entry = DB::transaction(function () use ($event, $hash, &$fields): Entry {
                $type = TransactionType::query()->where('key', $event->transactionTypeKey)->first()
                    ?? throw ValidationException::withMessages(['transaction_type.key' => [__('entries.type_unknown', ['key' => $event->transactionTypeKey])]]);

                $version = $this->drafts->version($type, $event->transactionTypeVersion);

                if ($version->mode !== TransactionTypeMode::Structured) {
                    throw ValidationException::withMessages(['transaction_type.key' => [__('entries.event_needs_structured', ['key' => $type->key])]]);
                }

                $fields = $version->fields ?? [];
                $reference = (string) ($event->references['source_document_number'] ?? $event->references['source_transaction_id'] ?? '');

                $entry = $this->drafts->create($type->key, $version->version, [
                    'accounting_date' => $event->accountingDate,
                    'description' => mb_substr("[{$event->sourceSystem}] {$type->label} {$reference}", 0, 500),
                    'currency' => $event->currency,
                    'exchange_rate' => $event->exchangeRate,
                    'values' => $this->values($fields, $event),
                ], null, [
                    'source' => 'integration',
                    'integration_client_id' => $event->clientId,
                    'source_system' => $event->sourceSystem,
                    'source_transaction_id' => (string) ($event->references['source_transaction_id'] ?? ''),
                    'source_document_number' => isset($event->references['source_document_number']) ? (string) $event->references['source_document_number'] : null,
                    'source_references' => $event->references,
                    'source_payload_hash' => $hash,
                ]);

                return $this->workflow->submit($entry->id, null, $event->clientId);
            });
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($this->named($e->errors(), $fields));
        }

        return $this->result($entry);
    }

    public function find(string $eventId, ?string $clientId = null): ?FinancialEventResult
    {
        $entry = Entry::query()
            ->where('source', 'integration')
            ->when($clientId !== null, fn ($query) => $query->where('integration_client_id', $clientId))
            ->find($eventId);

        return $entry === null ? null : $this->result($entry);
    }

    public function changes(?string $cursor, int $limit, ?string $clientId = null): FinancialEventChanges
    {
        $steps = EntryDecision::query()
            ->whereIn('entry_id', Entry::query()->where('source', 'integration')->when($clientId !== null, fn ($query) => $query->where('integration_client_id', $clientId))->select('id'))
            ->where('seq', '>', (int) $cursor)
            ->orderBy('seq')
            ->limit($limit)
            ->get();

        $entries = Entry::query()->whereIn('id', $steps->pluck('entry_id'))->get()->keyBy('id');

        $changes = array_values($steps->map(function (EntryDecision $step) use ($entries): FinancialEventChange {
            $posted = $step->status === EntryStatus::Posted->value;
            $entry = $entries[$step->entry_id];

            return new FinancialEventChange(
                cursor: (string) $step->seq,
                eventId: $step->entry_id,
                action: $step->action,
                status: $step->status,
                journalId: $posted ? $entry->journal_id : null,
                journalNumber: $posted ? $entry->journal_number : null,
                occurredAt: $step->created_at->toIso8601String(),
            );
        })->all());

        return new FinancialEventChanges($changes, $changes === [] ? $cursor : $changes[count($changes) - 1]->cursor);
    }

    private function result(Entry $entry, bool $replayed = false): FinancialEventResult
    {
        return new FinancialEventResult($entry->id, $entry->status->value, $entry->journal_id, $entry->journal_number, $replayed);
    }

    /**
     * The values of the fields from the sections of the event. What the event carries that no field
     * takes is refused by name, and a dimension without a value is no mistake.
     *
     * @param  list<array{name: string, type: string, label: string, required?: bool, account_ids?: list<string>, dimension_type?: string, allowed_roles?: list<string>}>  $fields
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function values(array $fields, FinancialEvent $event): array
    {
        $values = [];
        $takenAmounts = [];
        $takenDimensions = [];

        foreach ($fields as $field) {
            $name = $field['name'];

            switch (FieldType::from($field['type'])) {
                case FieldType::Amount:
                    $takenAmounts[] = $name;
                    $values[$name] = $event->amounts[$name] ?? null;
                    break;
                case FieldType::Dimension:
                    $takenDimensions[] = $field['dimension_type'] ?? '';
                    $values[$name] = $event->dimensions[$field['dimension_type'] ?? ''] ?? null;
                    break;
                case FieldType::Party:
                case FieldType::CashAccount:
                case FieldType::Allocations:
                    $values[$name] = $event->links[$field['type']] ?? null;
                    break;
                default:
                    $values[$name] = $event->references[$name] ?? null;
            }
        }

        $errors = [];

        foreach (array_diff(array_keys($event->amounts), $takenAmounts) as $key) {
            $errors["amounts.{$key}"] = [__('entries.value_unknown', ['field' => $key])];
        }

        foreach ($event->dimensions as $key => $value) {
            if ($value !== null && ! in_array($key, $takenDimensions, true)) {
                $errors["dimensions.{$key}"] = [__('entries.value_unknown', ['field' => $key])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * Gives the errors the names of the event instead of those of the entry: a problem with a field of the
     * entry is a problem with the section of the event that fills it.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<array{name: string, type: string, label: string, required?: bool, account_ids?: list<string>, dimension_type?: string, allowed_roles?: list<string>}>  $fields
     * @return array<string, list<string>>
     */
    private function named(array $errors, array $fields): array
    {
        $byName = [];

        foreach ($fields as $field) {
            $byName[$field['name']] = $field;
        }

        $named = [];

        foreach ($errors as $key => $messages) {
            $name = str_starts_with($key, 'values.') ? substr($key, 7) : null;
            $field = $name === null ? null : ($byName[$name] ?? null);

            $named[match (true) {
                $field === null => $key,
                $field['type'] === FieldType::Amount->value => "amounts.{$name}",
                $field['type'] === FieldType::Dimension->value => 'dimensions.'.($field['dimension_type'] ?? $name),
                in_array($field['type'], [FieldType::Party->value, FieldType::CashAccount->value, FieldType::Allocations->value], true) => $field['type'],
                default => "references.{$name}",
            }] = $messages;
        }

        return $named;
    }
}
