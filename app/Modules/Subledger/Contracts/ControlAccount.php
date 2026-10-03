<?php

namespace App\Modules\Subledger\Contracts;

/**
 * The control account a party's documents post to, as one version of its profile's mapping (SL-FR-02).
 */
final readonly class ControlAccount
{
    public function __construct(
        public ControlRole $role,
        public string $accountId,
        public string $postingProfileId,
        public int $version,
    ) {}
}
