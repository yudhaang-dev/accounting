<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\BankImportMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BankImportMapping $resource
 */
class BankImportMappingResource extends JsonResource
{
    /**
     * @return array{id: string, cash_account_id: string, delimiter: string, date_format: string, decimal_separator: string, has_header: bool, date_column: int, description_column: int|null, reference_column: int|null, amount_column: int|null, money_in_column: int|null, money_out_column: int|null, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'cash_account_id' => $this->resource->cash_account_id,
            'delimiter' => $this->resource->delimiter,
            'date_format' => $this->resource->date_format,
            'decimal_separator' => $this->resource->decimal_separator,
            'has_header' => $this->resource->has_header,
            'date_column' => $this->resource->date_column,
            'description_column' => $this->resource->description_column,
            'reference_column' => $this->resource->reference_column,
            'amount_column' => $this->resource->amount_column,
            'money_in_column' => $this->resource->money_in_column,
            'money_out_column' => $this->resource->money_out_column,
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
