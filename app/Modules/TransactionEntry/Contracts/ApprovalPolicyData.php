<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * The approval policy of a company in one version (TE-FR-09). Version 0 stands for a company that never
 * set one: no rule, so every entry needs one approval by a holder of `entries.approve`, and the creator
 * may approve it.
 */
final readonly class ApprovalPolicyData
{
    /**
     * @param  bool  $separationOfDuties  the creator of an entry cannot approve it
     * @param  list<ApprovalRule>  $rules
     */
    public function __construct(
        public int $version,
        public bool $separationOfDuties,
        public array $rules,
    ) {}

    public static function none(): self
    {
        return new self(0, false, []);
    }
}
