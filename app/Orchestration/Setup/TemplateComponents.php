<?php

namespace App\Orchestration\Setup;

use App\Support\Template\TemplateComponent;
use LogicException;

/** The template components the modules offer, by kind (ADR-0019). */
final class TemplateComponents
{
    /** @var array<string, TemplateComponent> */
    private array $byKind = [];

    /** @param  iterable<TemplateComponent>  $components */
    public function __construct(iterable $components)
    {
        foreach ($components as $component) {
            $this->byKind[$component->kind()] = $component;
        }
    }

    /** @throws LogicException a pack names a kind no module provides */
    public function for(string $kind): TemplateComponent
    {
        return $this->byKind[$kind] ?? throw new LogicException("No module provides template components of kind {$kind}.");
    }
}
