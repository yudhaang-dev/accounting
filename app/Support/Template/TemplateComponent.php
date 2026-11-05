<?php

namespace App\Support\Template;

use Illuminate\Validation\ValidationException;

/**
 * One kind of template a module can install into a company, so a template pack (ADR-0019) can be put
 * together in the orchestration layer without a module importing another. Implementations are tagged
 * with {@see self::TAG}. Nothing is created by `preview`; `install` is only reachable after the pack was
 * confirmed (X-AC-06), and runs inside the transaction of the pack.
 */
interface TemplateComponent
{
    public const TAG = 'template.components';

    /** The kind a pack manifest names: `chart_of_accounts`, `party_roles`, `transaction_types`, `report_definitions`. */
    public function kind(): string;

    /** The permission that manages what this kind creates; a caller without it cannot install the component. */
    public function permission(): string;

    /**
     * What the template would create in the current company, each item marked when the company already has it.
     *
     * @throws ValidationException unknown template
     */
    public function preview(string $templateKey): ComponentPreview;

    /**
     * Creates the given items, all or none. The caller passes only items the company does not have yet.
     *
     * @param  list<string>  $itemKeys
     *
     * @throws ValidationException an item the template lacks, or something the company is missing for it
     */
    public function install(string $templateKey, array $itemKeys, int $actorId): void;
}
