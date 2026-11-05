<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;
use Illuminate\Validation\ValidationException;

/** Transaction types as a component of a template pack (ADR-0019). */
final class TransactionTypeTemplateComponent implements TemplateComponent
{
    public function __construct(private readonly TransactionTypeTemplates $templates, private readonly ImportTransactionTypeTemplate $import) {}

    public function kind(): string
    {
        return 'transaction_types';
    }

    public function permission(): string
    {
        return 'policies.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $template = $this->templates->find($templateKey)
            ?? throw ValidationException::withMessages(['template' => [__('entries.template_unknown')]]);
        $taken = TransactionType::query()->pluck('key')->all();

        return new ComponentPreview(
            $this->kind(),
            $template['key'],
            $template['name'],
            $template['version'],
            array_map(fn (array $type): PreviewItem => new PreviewItem($type['key'], $type['label'], in_array($type['key'], $taken, true)), $template['types']),
        );
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        ($this->import)($templateKey, $itemKeys);
    }
}
