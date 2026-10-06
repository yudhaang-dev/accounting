<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\JournalSource;

/**
 * What narrows the ledger lines a report adds up (RP-FR-04). Every part is optional and an empty
 * filter keeps every line, including those without dimensions.
 */
final class ReportFilter
{
    /**
     * @param  array<string, string>  $dimensions  dimension type key => value ID; a parent value includes its descendants
     * @param  list<string>  $transactionTypeKeys
     * @param  string|null  $planVersionId  the version that `plan` columns read when the column names none; not a ledger filter, so it is left out of `toArray()`
     */
    public function __construct(
        public readonly array $dimensions = [],
        public readonly ?string $accountId = null,
        public readonly array $transactionTypeKeys = [],
        public readonly ?JournalSource $source = null,
        public readonly ?string $planVersionId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $params  validated report parameters
     */
    public static function fromParameters(array $params): self
    {
        /** @var array<string, string|null> $dimensions */
        $dimensions = $params['dimensions'] ?? [];
        /** @var list<string> $keys */
        $keys = $params['transaction_type_keys'] ?? [];

        return new self(
            array_filter($dimensions, fn (?string $id): bool => $id !== null && $id !== ''),
            $params['account_id'] ?? null,
            array_values(array_unique($keys)),
            isset($params['source']) ? JournalSource::from($params['source']) : null,
            $params['plan_version_id'] ?? null,
        );
    }

    /**
     * @return array{account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}
     */
    public function toArray(): array
    {
        return ['account_id' => $this->accountId, 'dimensions' => $this->dimensions, 'transaction_type_keys' => $this->transactionTypeKeys, 'source' => $this->source?->value];
    }
}
