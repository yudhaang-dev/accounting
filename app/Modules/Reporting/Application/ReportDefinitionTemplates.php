<?php

namespace App\Modules\Reporting\Application;

/**
 * Report definition templates shipped as data files in Database/templates. A template is only a
 * suggestion: nothing in it is applied to a company without an explicit, confirmed import (RP-FR-01, X-AC-03).
 *
 * @phpstan-type TemplateDefinition array{key: string, name: string, kind: string, structure: array<string, mixed>}
 * @phpstan-type Template array{key: string, name: string, version: int, definitions: list<TemplateDefinition>}
 */
final class ReportDefinitionTemplates
{
    /** @var array<string, Template>|null */
    private ?array $templates = null;

    /** @return array<string, Template> keyed by template key */
    public function all(): array
    {
        if ($this->templates === null) {
            $this->templates = [];

            foreach (glob(dirname(__DIR__).'/Database/templates/*.php') ?: [] as $file) {
                /** @var Template $template */
                $template = require $file;
                $this->templates[$template['key']] = $template;
            }
        }

        return $this->templates;
    }

    /** @return Template|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }
}
