<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\EntryStatus;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Modules\TransactionEntry\Domain\VersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Platform\Numbering\Numbering;
use App\Platform\Numbering\NumberSeriesNotFound;
use App\Platform\Numbering\ResetPeriod;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits draft entries (TE-FR-07). A draft is only checked for shape; the full validation
 * of accounts, period and balance happens on submit and again on posting (TE-FR-08).
 *
 * @phpstan-type LineInput array{account_id: string, debit?: string|null, credit?: string|null, description?: string|null}
 * @phpstan-type EntryInput array{accounting_date: string, document_date?: string|null, description: string, lines: list<LineInput>}
 */
final class EntryDrafts
{
    public function __construct(
        private readonly Numbering $numbering,
        private readonly Audit $audit,
        private readonly CompanyContext $company,
    ) {}

    /**
     * @param  EntryInput  $data
     *
     * @throws ValidationException when the transaction type or version does not exist
     * @throws ApiException TRANSACTION_TYPE_VERSION_RETIRED
     */
    public function create(string $typeKey, ?int $version, array $data, int $userId): Entry
    {
        return DB::transaction(function () use ($typeKey, $version, $data, $userId): Entry {
            $type = TransactionType::query()->where('key', $typeKey)->first()
                ?? throw ValidationException::withMessages(['transaction_type_key' => [__('entries.type_unknown', ['key' => $typeKey])]]);

            $resolved = $this->version($type, $version);

            $entry = Entry::create([
                'transaction_type_id' => $type->id,
                'transaction_type_key' => $type->key,
                'transaction_type_version' => $resolved->version,
                'number' => $this->nextNumber($type->key, CarbonImmutable::parse($data['accounting_date'])),
                'accounting_date' => $data['accounting_date'],
                'document_date' => $data['document_date'] ?? null,
                'description' => $data['description'],
                'lines' => $this->lines($data['lines']),
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
     * @throws ApiException INVALID_STATE_TRANSITION when the entry is not a draft, VERSION_CONFLICT
     */
    public function update(string $id, array $data, int $expectedVersion): Entry
    {
        return DB::transaction(function () use ($id, $data, $expectedVersion): Entry {
            $entry = Entry::query()->lockForUpdate()->findOrFail($id);

            if ($entry->status !== EntryStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $entry->updateLocked([
                'accounting_date' => $data['accounting_date'],
                'document_date' => $data['document_date'] ?? null,
                'description' => $data['description'],
                'lines' => $this->lines($data['lines']),
            ], $expectedVersion);

            $this->audit->record('entry.updated', "Entry {$entry->number} updated.", $entry, companyId: $entry->company_id);

            return $entry->refresh();
        });
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
     * @param  list<LineInput>  $lines
     * @return list<array{account_id: string, debit: string, credit: string, description: string|null}>
     */
    private function lines(array $lines): array
    {
        return array_map(fn (array $line): array => [
            'account_id' => $line['account_id'],
            'debit' => $line['debit'] ?? '0',
            'credit' => $line['credit'] ?? '0',
            'description' => $line['description'] ?? null,
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
