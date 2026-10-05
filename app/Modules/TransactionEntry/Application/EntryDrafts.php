<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\EntryStatus;
use App\Modules\TransactionEntry\Domain\FieldValues;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Modules\TransactionEntry\Domain\VersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Access\DataScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits draft entries (TE-FR-07). A draft is only checked for shape; the full validation
 * of accounts, period and balance happens on submit and again on posting (TE-FR-08).
 *
 * @phpstan-type LineInput array{account_id: string, debit?: string|null, credit?: string|null, description?: string|null, dimensions?: array<string, string|null>|null, currency?: string|null, exchange_rate?: string|null}
 * @phpstan-type EntryInput array{accounting_date: string, document_date?: string|null, description: string, currency?: string|null, exchange_rate?: string|null, auto_reverse_on?: string|null, replaces_entry_id?: string|null, lines?: list<LineInput>, values?: array<string, mixed>}
 */
final class EntryDrafts
{
    public function __construct(
        private readonly EntryNumbers $numbers,
        private readonly Audit $audit,
        private readonly DataScopeContext $scope,
        private readonly EntryLines $entryLines,
        private readonly EntryRates $rates,
    ) {}

    /**
     * @param  EntryInput  $data
     * @param  array<string, mixed>  $origin  columns for an entry that does not come from a user: `source` and the fields of the financial event
     *
     * @throws ValidationException when the transaction type or version does not exist
     * @throws ApiException TRANSACTION_TYPE_VERSION_RETIRED, FORBIDDEN when a line is outside the data scope of the user
     */
    public function create(string $typeKey, ?int $version, array $data, ?int $userId, array $origin = []): Entry
    {
        return DB::transaction(function () use ($typeKey, $version, $data, $userId, $origin): Entry {
            $type = TransactionType::query()->where('key', $typeKey)->first()
                ?? throw ValidationException::withMessages(['transaction_type_key' => [__('entries.type_unknown', ['key' => $typeKey])]]);

            $resolved = $this->version($type, $version);
            $content = $this->content($resolved, $data);

            $entry = Entry::create([
                'transaction_type_id' => $type->id,
                'transaction_type_key' => $type->key,
                'transaction_type_version' => $resolved->version,
                'number' => $type->number_policy === TransactionType::NUMBER_ON_CREATE ? $this->numbers->next($type->key, CarbonImmutable::parse($data['accounting_date'])) : null,
                'accounting_date' => $data['accounting_date'],
                'document_date' => $data['document_date'] ?? null,
                'description' => $data['description'],
                ...$content,
                ...$this->links($data, $data['accounting_date']),
                ...$origin,
                'created_by' => $userId,
            ])->refresh();

            $this->audit->record('entry.created', 'Entry '.($entry->number ?? $entry->id).' created.', $entry, after: ['number' => $entry->number, 'status' => $entry->status->value], companyId: $entry->company_id);

            return $entry;
        });
    }

    /**
     * Replaces the content of a draft. `lock_version` makes a stale edit fail with VERSION_CONFLICT.
     *
     * @param  EntryInput  $data
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the entry is not a draft, VERSION_CONFLICT, FORBIDDEN when a line is outside the data scope of the user
     */
    public function update(string $id, array $data, int $expectedVersion): Entry
    {
        return DB::transaction(function () use ($id, $data, $expectedVersion): Entry {
            $entry = Entry::query()->lockForUpdate()->findOrFail($id);

            if ($entry->status !== EntryStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            if ($entry->reverses_journal_id !== null) {
                throw ValidationException::withMessages(['lines' => [__('entries.reversal_not_editable')]]);
            }

            $version = $entry->typeVersion();

            $entry->updateLocked([
                'accounting_date' => $data['accounting_date'],
                'document_date' => $data['document_date'] ?? null,
                'description' => $data['description'],
                ...$this->content($version, $data),
                ...$this->links($data, $data['accounting_date']),
            ], $expectedVersion);

            $this->audit->record('entry.updated', 'Entry '.($entry->number ?? $entry->id).' updated.', $entry, companyId: $entry->company_id);

            return $entry->refresh();
        });
    }

    /**
     * The date the system reverses the entry on and the entry it replaces, both optional (TE-FR-11).
     * A reversal date must come after the accounting date; a replaced entry must be a posted entry.
     *
     * @param  EntryInput  $data
     * @return array{auto_reverse_on: string|null, replaces_entry_id: string|null}
     *
     * @throws ValidationException
     */
    private function links(array $data, string $accountingDate): array
    {
        $on = $data['auto_reverse_on'] ?? null;
        $replaces = $data['replaces_entry_id'] ?? null;
        $errors = [];

        if ($on !== null && $on <= $accountingDate) {
            $errors['auto_reverse_on'] = [__('entries.auto_reverse_after')];
        }

        if ($replaces !== null) {
            $replaced = Entry::query()->find($replaces);

            if ($replaced === null || $replaced->status !== EntryStatus::Posted || $replaced->reverses_journal_id !== null) {
                $errors['replaces_entry_id'] = [__('entries.replaces_unknown')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['auto_reverse_on' => $on, 'replaces_entry_id' => $replaces];
    }

    /**
     * Every line must carry a value inside the data scope for each dimension the scope limits
     * (IA-FR-03); a line without such a dimension is outside it.
     *
     * @param  list<LineInput>  $lines
     *
     * @throws ApiException FORBIDDEN
     */
    private function assertWithinDataScope(array $lines): void
    {
        $scope = $this->scope->current();

        foreach ($lines as $index => $line) {
            if (! $scope->allows($line['dimensions'] ?? [])) {
                throw new ApiException(ErrorCode::Forbidden, errors: ["lines.{$index}.dimensions" => [__('entries.line_outside_scope')]]);
            }
        }
    }

    /**
     * @throws ValidationException when the requested version does not exist
     * @throws ApiException TRANSACTION_TYPE_VERSION_RETIRED
     */
    public function version(TransactionType $type, ?int $requested): TransactionTypeVersion
    {
        $versions = TransactionTypeVersion::query()->where('transaction_type_id', $type->id);

        if ($requested !== null) {
            $found = (clone $versions)->where('version', $requested)->first()
                ?? throw ValidationException::withMessages(['transaction_type_version' => [__('entries.version_unknown', ['version' => $requested])]]);
        } else {
            $found = (clone $versions)->where('status', VersionStatus::Published)->orderByDesc('version')->first()
                ?? $versions->orderByDesc('version')->firstOrFail();
        }

        if ($found->status === VersionStatus::Retired) {
            throw new ApiException(ErrorCode::TransactionTypeVersionRetired);
        }

        if ($found->status !== VersionStatus::Published) {
            throw ValidationException::withMessages(['transaction_type_version' => [__('entries.version_unpublished', ['version' => $found->version])]]);
        }

        return $found;
    }

    /**
     * The `lines` and `field_values` columns for the input, by the mode of the version: a `free_lines`
     * entry carries its lines, a `structured` one carries field values and its lines come from the posting
     * rules (TE-FR-05). The data scope applies to the resulting lines.
     *
     * @param  EntryInput  $data
     * @return array{lines: list<array{account_id: string, debit: string, credit: string, description: string|null, dimensions: array<string, string>}>, field_values: array<string, mixed>|null}
     *
     * @throws ValidationException
     * @throws ApiException FORBIDDEN
     */
    private function content(TransactionTypeVersion $version, array $data): array
    {
        // Reversing the journal of a document is the subledger's to do, which entries cannot ask for yet.
        if ($version->subledger_route !== null && ($data['auto_reverse_on'] ?? null) !== null) {
            throw ValidationException::withMessages(['auto_reverse_on' => [__('entries.route_no_auto_reverse')]]);
        }

        if ($version->mode === TransactionTypeMode::Structured) {
            if (isset($data['lines'])) {
                throw ValidationException::withMessages(['lines' => [__('entries.lines_not_allowed')]]);
            }

            $values = FieldValues::present($data['values'] ?? []);

            if (($errors = FieldValues::errors($version->fields ?? [], $values)) !== []) {
                throw ValidationException::withMessages($errors);
            }

            $currency = $this->rates->foreign($data['currency'] ?? null);
            $rate = $data['exchange_rate'] ?? null;
            $this->rates->assertAllowed($version, $currency, $rate, 'currency', 'exchange_rate');

            $lines = $this->entryLines->generate($version, $values, $data['accounting_date'], $currency, $rate, strict: false);
            $this->assertWithinDataScope($lines);

            return ['lines' => $lines, 'field_values' => $values, 'currency' => $currency, 'exchange_rate' => $rate];
        }

        if (isset($data['values'])) {
            throw ValidationException::withMessages(['values' => [__('entries.values_not_allowed')]]);
        }

        if (! isset($data['lines'])) {
            throw ValidationException::withMessages(['lines' => [__('entries.lines_required')]]);
        }

        if (isset($data['currency']) || isset($data['exchange_rate'])) {
            throw ValidationException::withMessages([isset($data['currency']) ? 'currency' : 'exchange_rate' => [__('entries.currency_per_line')]]);
        }

        foreach ($data['lines'] as $index => $line) {
            $this->rates->assertAllowed($version, $line['currency'] ?? null, $line['exchange_rate'] ?? null, "lines.{$index}.currency", "lines.{$index}.exchange_rate");
        }

        $this->assertWithinDataScope($data['lines']);

        return ['lines' => $this->lines($data['lines']), 'field_values' => null, 'currency' => null, 'exchange_rate' => null];
    }

    /**
     * @param  list<LineInput>  $lines
     * @return list<array{account_id: string, debit: string, credit: string, description: string|null, dimensions: array<string, string>}>
     */
    private function lines(array $lines): array
    {
        return array_map(fn (array $line): array => [
            'account_id' => $line['account_id'],
            'debit' => $line['debit'] ?? '0',
            'credit' => $line['credit'] ?? '0',
            'description' => $line['description'] ?? null,
            'dimensions' => array_filter($line['dimensions'] ?? [], fn (?string $valueId): bool => $valueId !== null),
            'currency' => $this->rates->foreign($line['currency'] ?? null),
            'exchange_rate' => $this->rates->foreign($line['currency'] ?? null) === null ? null : ($line['exchange_rate'] ?? null),
        ], $lines);
    }
}
