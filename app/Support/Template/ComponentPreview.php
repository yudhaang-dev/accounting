<?php

namespace App\Support\Template;

final readonly class ComponentPreview
{
    /** @param  list<PreviewItem>  $items */
    public function __construct(
        public string $kind,
        public string $templateKey,
        public string $name,
        public int $version,
        public array $items,
    ) {}
}
