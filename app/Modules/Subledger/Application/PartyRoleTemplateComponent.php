<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\PartyRole;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;

/**
 * The party roles a company may adopt, as a component of a template pack (ADR-0019). The roles have one
 * template, `default`.
 */
final class PartyRoleTemplateComponent implements TemplateComponent
{
    public const TEMPLATE = 'default';

    public function __construct(private readonly PartyRoleTemplates $templates, private readonly ManagePartyRoles $roles) {}

    public function kind(): string
    {
        return 'party_roles';
    }

    public function permission(): string
    {
        return 'parties.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $taken = PartyRole::query()->pluck('key')->all();

        return new ComponentPreview(
            $this->kind(),
            self::TEMPLATE,
            (string) __('party_roles.template_name'),
            1,
            array_map(fn (array $role): PreviewItem => new PreviewItem($role['key'], $role['label'], in_array($role['key'], $taken, true)), $this->templates->all()),
        );
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        $this->roles->import($itemKeys);
    }
}
