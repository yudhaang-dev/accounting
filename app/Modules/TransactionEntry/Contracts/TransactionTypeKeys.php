<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * Transaction type keys of a company (TE-FR-01), for configuration elsewhere that names keys and
 * must reject one that does not exist.
 */
interface TransactionTypeKeys
{
    /**
     * @param  list<string>  $keys
     * @return list<string> those of `$keys` the company has no transaction type for
     */
    public function missing(string $companyId, array $keys): array;
}
