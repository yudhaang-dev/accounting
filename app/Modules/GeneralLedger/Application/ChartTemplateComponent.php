<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;
use Illuminate\Validation\ValidationException;

/**
 * The chart of accounts as a component of a template pack (ADR-0019). Only an empty chart takes a
 * template, so a company that already has accounts shows every item as existing and gets nothing.
 */
final class ChartTemplateComponent implements TemplateComponent
{
    public function __construct(private readonly ChartTemplates $templates, private readonly ImportAccountTemplate $import) {}

    public function kind(): string
    {
        return 'chart_of_accounts';
    }

    public function permission(): string
    {
        return 'accounts.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $template = $this->templates->find($templateKey)
            ?? throw ValidationException::withMessages(['template' => [__('accounts.template_unknown')]]);
        $exists = Account::query()->exists();

        return new ComponentPreview(
            $this->kind(),
            $template['key'],
            $template['name'],
            $template['version'],
            array_map(fn (array $row): PreviewItem => new PreviewItem($row['code'], $row['name'], $exists, $row['optional']), $template['accounts']),
        );
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        $template = $this->templates->find($templateKey)
            ?? throw ValidationException::withMessages(['template' => [__('accounts.template_unknown')]]);
        $exclude = array_values(array_diff(array_column($template['accounts'], 'code'), $itemKeys));

        ($this->import)($templateKey, $exclude);
    }
}
