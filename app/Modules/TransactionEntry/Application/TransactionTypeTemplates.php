<?php

namespace App\Modules\TransactionEntry\Application;

/**
 * Transaction type templates shipped as data files in Database/templates. A template is only a
 * suggestion: nothing in it is applied to a company without an explicit, confirmed import (TE-FR-01, X-AC-03).
 *
 * @phpstan-import-type FieldDefinition from \App\Modules\TransactionEntry\Domain\FieldSchema
 *
 * @phpstan-type TemplateType array{key: string, label: string, mode: string, fields: list<FieldDefinition>, posting_rules: list<array<string, mixed>>, subledger_route?: array<string, mixed>|null}
 * @phpstan-type Template array{key: string, name: string, version: int, types: list<TemplateType>}
 */
final class TransactionTypeTemplates
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
