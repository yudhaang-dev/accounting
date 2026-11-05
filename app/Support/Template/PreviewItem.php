<?php

namespace App\Support\Template;

final readonly class PreviewItem
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $exists,
        public bool $optional = false,
    ) {}
}
