<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Domain\ReportDefinition;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;
use Illuminate\Validation\ValidationException;

/** Report definitions as a component of a template pack (ADR-0019). */
final class ReportTemplateComponent implements TemplateComponent
{
    public function __construct(private readonly ReportDefinitionTemplates $templates, private readonly ImportReportDefinitionTemplate $import) {}

    public function kind(): string
    {
        return 'report_definitions';
    }

    public function permission(): string
    {
        return 'report-definitions.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $template = $this->templates->find($templateKey)
            ?? throw ValidationException::withMessages(['template' => [__('reports.template_unknown')]]);
        $taken = ReportDefinition::query()->pluck('key')->all();

        return new ComponentPreview(
            $this->kind(),
            $template['key'],
            $template['name'],
            $template['version'],
            array_map(fn (array $definition): PreviewItem => new PreviewItem($definition['key'], $definition['name'], in_array($definition['key'], $taken, true)), $template['definitions']),
        );
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        ($this->import)($templateKey, $itemKeys, $actorId);
    }
}
