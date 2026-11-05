<?php

namespace App\Orchestration\Setup;

/**
 * What a pack would create in the current company, component by component, for review before it is
 * confirmed (X-AC-06). Nothing is created. Items the company already has are marked, and the install
 * leaves them alone.
 *
 * @phpstan-import-type Pack from TemplatePacks
 *
 * @phpstan-type DescribedComponent array{id: string, kind: string, template: string, name: string, version: int, items: list<array{key: string, label: string, exists: bool, optional: bool, conflict: string|null}>}
 */
final class DescribeTemplatePack
{
    public function __construct(private readonly TemplateComponents $components) {}

    /**
     * @param  Pack  $pack
     * @return list<DescribedComponent>
     */
    public function __invoke(array $pack): array
    {
        $described = [];

        foreach ($pack['components'] as $entry) {
            $preview = $this->components->for($entry['kind'])->preview($entry['template']);

            $described[] = [
                'id' => $entry['id'],
                'kind' => $entry['kind'],
                'template' => $preview->templateKey,
                'name' => $preview->name,
                'version' => $preview->version,
                'items' => array_map(fn ($item): array => ['key' => $item->key, 'label' => $item->label, 'exists' => $item->exists, 'optional' => $item->optional, 'conflict' => $item->conflict], $preview->items),
            ];
        }

        return $described;
    }
}
