<?php

namespace App\Modules\MasterData\Contracts;

/**
 * Profile fields to change; a field that is absent from the array stays as it is.
 */
final readonly class CompanyChanges
{
    /**
     * @param  array<string, mixed>  $attributes  any of: name, legal_name, tax_id, functional_currency, timezone, default_locale
     */
    public function __construct(public array $attributes) {}
}
