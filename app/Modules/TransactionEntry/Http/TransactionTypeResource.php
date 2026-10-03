<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Application\EntryNumbers;
use App\Modules\TransactionEntry\Domain\TransactionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TransactionType $resource
 */
class TransactionTypeResource extends JsonResource
{
    /**
     * @return array{id: string, key: string, label: string, numbering: array{pattern: string, padding: int, reset: string, policy: string}, versions: list<array{version: int, mode: string, status: string, fields: list<array<string, mixed>>|null, posting_rules: list<array<string, mixed>>|null, allowed_currencies: list<string>|null, rate_type_key: string|null, allow_rate_input: bool}>, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'numbering' => [...app(EntryNumbers::class)->describe($this->resource->key), 'policy' => $this->resource->number_policy],
            'versions' => $this->versions(),
            'lock_version' => $this->resource->lock_version,
        ];
    }

    /**
     * @return list<array{version: int, mode: string, status: string, fields: list<array<string, mixed>>|null, posting_rules: list<array<string, mixed>>|null, allowed_currencies: list<string>|null, rate_type_key: string|null, allow_rate_input: bool}>
     */
    private function versions(): array
    {
        $versions = [];

        foreach ($this->resource->versions as $version) {
            $versions[] = [
                'version' => (int) $version->version,
                'mode' => $version->mode->value,
                'status' => $version->status->value,
                'fields' => $version->fields,
                'posting_rules' => $version->posting_rules,
                'allowed_currencies' => $version->allowed_currencies,
                'rate_type_key' => $version->rate_type_key,
                'allow_rate_input' => $version->allow_rate_input,
            ];
        }

        return $versions;
    }
}
