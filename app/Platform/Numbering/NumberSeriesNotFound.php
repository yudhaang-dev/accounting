<?php

namespace App\Platform\Numbering;

use RuntimeException;

class NumberSeriesNotFound extends RuntimeException
{
    public function __construct(public readonly string $companyId, public readonly string $key)
    {
        parent::__construct("Number series [{$key}] is not defined for company [{$companyId}].");
    }
}
