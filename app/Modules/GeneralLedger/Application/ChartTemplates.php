<?php

namespace App\Modules\GeneralLedger\Application;

use LogicException;

/**
 * Chart of accounts templates shipped as data files in Database/templates. A template is only a
 * suggestion: nothing in it is applied to a company without an explicit, confirmed import (X-AC-03).
 *
 * A template may `extends` another and then lists only the accounts it adds, so the base chart is not
 * repeated (ADR-0019). An `additive` template is an add-on module: it adds accounts to a chart that
 * already exists, so it is never offered as a chart of its own, only through a template pack.
 *
 * @phpstan-type TemplateAccount array{code: string, name: string, class: string, normal: string|null, parent: string|null, optional: bool, note: string|null}
 * @phpstan-type Template array{key: string, name: string, version: int, accounts: list<TemplateAccount>, extends?: string|null, additive?: bool}
 */
final class ChartTemplates
{
    /** @var array<string, Template>|null */
    private ?array $templates = null;

    /**
     * The templates that are a whole chart, to import into an empty chart.
     *
     * @return array<string, Template> keyed by template key
     */
    public function all(): array
    {
        $whole = array_filter($this->load(), fn (array $template): bool => ! ($template['additive'] ?? false));

        // The chart a company gets when it picks the first one: a whole chart comes before the variants of it.
        uasort($whole, fn (array $a, array $b): int => isset($a['extends']) <=> isset($b['extends']));

        return $whole;
    }

    /**
     * A template that is a whole chart.
     *
     * @return Template|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Any template, an add-on included; what a template pack installs.
     *
     * @return Template|null
     */
    public function findAny(string $key): ?array
    {
        return $this->load()[$key] ?? null;
    }

    /**
     * @return array<string, Template> keyed by template key, every `extends` resolved
     *
     * @throws LogicException a template that extends an unknown or extending template, or repeats an account code
     */
    private function load(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $raw = [];
        $directories = [dirname(__DIR__).'/Database/templates', ...(array) config('templates.extra_chart_paths', [])];

        foreach ($directories as $directory) {
            foreach (glob(rtrim((string) $directory, '/\\').'/*.php') ?: [] as $file) {
                /** @var Template $template */
                $template = require $file;
                $raw[$template['key']] = $template;
            }
        }

        $this->templates = [];

        foreach ($raw as $key => $template) {
            $base = $template['extends'] ?? null;

            if ($base !== null) {
                $parent = $raw[$base] ?? throw new LogicException("Chart template {$key} extends unknown template {$base}.");

                if (($parent['extends'] ?? null) !== null) {
                    throw new LogicException("Chart template {$key} extends {$base}, which extends another template.");
                }

                $template['accounts'] = [...$parent['accounts'], ...$template['accounts']];
            }

            $codes = array_column($template['accounts'], 'code');

            if (count($codes) !== count(array_unique($codes))) {
                throw new LogicException("Chart template {$key} repeats an account code.");
            }

            $this->templates[$key] = $template;
        }

        return $this->templates;
    }
}
