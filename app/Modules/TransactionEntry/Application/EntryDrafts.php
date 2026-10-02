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
use App\Platform\Numbering\Numbering;
use App\Platform\Numbering\NumberSeriesNotFound;
use App\Platform\Numbering\ResetPeriod;
use App\Support\Access\DataScopeContext;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits draft entries (TE-FR-07). A draft is only checked for shape; the full validation
 * of accounts, period and balance happens on submit and again on posting (TE-FR-08).
 *
 * @phpstan-type LineInput array{account_id: string, debit?: string|null, credit?: string|null, description?: string|null, dimensions?: array<string, string|null>|null}
 * @phpstan-type EntryInput array{accounting_date: string, document_date?: string|null, description: string, lines?: list<LineInput>, values?: array<string, mixed>}
 */
final class EntryDrafts
{
    public function __construct(
        private readonly Numbering $numbering,
        private readonly Audit $audit,
        private readonly CompanyContext $company,
        private readonly DataScopeContext $scope,
        private readonly EntryLines $entryLines,
    ) {}

    /**
     * @param  EntryInput  $data
     *
     * @throws ValidationException when the transaction type or version does not exist
     * @throws ApiException TRANSACTION_TYPE_VERSION_RETIRED, FORBIDDEN when a line is outside the data scope of the user
     */
    public function create(string $typeKey, ?int $version, array $data, int $userId): Entry
    {
        return DB::transaction(function () use ($typeKey, $version, $data, $userId): Entry {
            $type = TransactionType::query()->where('key', $typeKey)->first()
                ?? throw ValidationException::withMessages(['transaction_type_key' => [__('entries.type_unknown', ['key' => $typeKey])]]);

            $resolved = $this->version($type, $version);
            $content = $this->content($resolved, $data);

            $entry = Entry::create([
                'transaction_type_id' => $type->id,
                'transaction_type_key' => $type->key,
                'transaction_type_version' => $resolved->version,
                'number' => $this->nextNumber($type->key, CarbonImmutable::parse($data['accounting_date'])),
                'accounting_date' => $data['accounting_date'],
                'document_date' => $data['document_date'] ?? null,
                'description' => $data['description'],
                ...$content,
                'created_by' => $userId,
            ])->refresh();

            $this->audit->record('entry.created', "Entry {$entry->number} created.", $entry, after: ['number' => $entry->number, 'status' => $entry->status->value], companyId: $entry->company_id);

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

            $version = TransactionTypeVersion::query()
                ->where('transaction_type_id', $entry->transaction_type_id)
                ->where('version', $entry->transaction_type_version)
                ->firstOrFail();

            $entry->updateLocked([
                'accounting_date' => $data['accounting_date'],
                'document_date' => $data['document_date'] ?? null,
                'description' => $data['description'],
                ...$this->content($version, $data),
            ], $expectedVersion);

            $this->audit->record('entry.updated', "Entry {$entry->number} updated.", $entry, companyId: $entry->company_id);

            return $entry->refresh();
        });
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
    private function version(TransactionType $type, ?int $requested): TransactionTypeVersion
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
        if ($version->mode === TransactionTypeMode::Structured) {
            if (isset($data['lines'])) {
                throw ValidationException::withMessages(['lines' => [__('entries.lines_not_allowed')]]);
            }

            $values = FieldValues::present($data['values'] ?? []);

            if (($errors = FieldValues::errors($version->fields ?? [], $values)) !== []) {
                throw ValidationException::withMessages($errors);
            }

            $lines = $this->entryLines->generate($version, $values, $data['accounting_date']);
            $this->assertWithinDataScope($lines);

            return ['lines' => $lines, 'field_values' => $values];
        }

        if (isset($data['values'])) {
            throw ValidationException::withMessages(['values' => [__('entries.values_not_allowed')]]);
        }

        if (! isset($data['lines'])) {
            throw ValidationException::withMessages(['lines' => [__('entries.lines_required')]]);
        }

        $this->assertWithinDataScope($data['lines']);

        return ['lines' => $this->lines($data['lines']), 'field_values' => null];
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
        ], $lines);
    }

    /**
     * Issues the next entry number of the type, creating its series on first use (TE-FR-16).
     */
    private function nextNumber(string $typeKey, CarbonImmutable $date): string
    {
        $companyId = $this->company->require();
        $series = "entry:{$typeKey}";

        try {
            return $this->numbering->next($companyId, $series, $date);
        } catch (NumberSeriesNotFound) {
            /** @var array{pattern: string, padding: int, reset: string} $default */
            $default = config('entries.number');

            $this->numbering->define($companyId, $series, $default['pattern'], $default['padding'], ResetPeriod::from($default['reset']));

            return $this->numbering->next($companyId, $series, $date);
        }
    }
}
