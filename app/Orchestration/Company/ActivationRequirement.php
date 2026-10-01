<?php

namespace App\Orchestration\Company;

/**
 * A prerequisite for activating a company (MD-FR-01), such as a confirmed chart of accounts or a
 * fiscal calendar. Each requirement is an adapter to the Contracts of the module that owns the
 * fact; register it with the `company.activation_requirements` container tag.
 */
interface ActivationRequirement
{
    /** Stable key, used as the field name in the COMPANY_NOT_READY error. */
    public function key(): string;

    /** Explains what is missing, already translated. */
    public function message(): string;

    public function isMet(string $companyId): bool;
}
