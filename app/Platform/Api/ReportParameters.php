<?php

namespace App\Platform\Api;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * Common query parameters of report and export endpoints (API-TR-09). Modules decide which of them
 * a given report requires or supports; this class only validates their shape. Dimension and
 * transaction type keys are checked against company data by the owning module.
 */
final class ReportParameters extends Data
{
    /**
     * @param  array<string, string|null>|null  $dimensions  dimension type key => dimension value ID
     * @param  list<string>|null  $transaction_type_keys
     */
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public ?CarbonImmutable $from = null,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public ?CarbonImmutable $to = null,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public ?CarbonImmutable $as_of = null,
        public ?string $account_id = null,
        public ?array $dimensions = null,
        public ?array $transaction_type_keys = null,
        public ?string $source = null,
        public ?string $plan_version_id = null,
        public ?string $basis = null,
        public ?string $compare = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $key = 'regex:/^[a-z][a-z0-9_.]*$/';

        return [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to', 'prohibits:as_of'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'prohibits:from,to'],
            'account_id' => ['nullable', 'ulid'],
            'dimensions' => ['nullable', 'array'],
            'dimensions.*' => ['nullable', 'ulid'],
            'transaction_type_keys' => ['nullable', 'array'],
            'transaction_type_keys.*' => ['string', $key, 'max:64'],
            'source' => ['nullable', 'string', 'max:64'],
            'plan_version_id' => ['nullable', 'ulid'],
            'basis' => ['nullable', 'string', 'alpha_dash:ascii', 'max:32'],
            'compare' => ['nullable', 'string', 'alpha_dash:ascii', 'max:32'],
        ];
    }
}
