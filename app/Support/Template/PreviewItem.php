<?php

namespace App\Support\Template;

final readonly class PreviewItem
{
    /**
     * @param  bool  $exists  the company already has it, so the install leaves it alone
     * @param  string|null  $conflict  why it cannot be added as it is (the company has something else under the same key); the caller has to leave it out
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $exists,
        public bool $optional = false,
        public ?string $conflict = null,
    ) {}
}
