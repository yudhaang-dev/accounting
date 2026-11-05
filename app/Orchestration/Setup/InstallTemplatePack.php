<?php

namespace App\Orchestration\Setup;

use App\Modules\IdentityAccess\Contracts\AccessContext;
use App\Platform\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Installs the components of a pack the user picked, after they confirmed the review (X-AC-06, ADR-0019). The
 * confirmation is enforced at the HTTP boundary; this action is only reachable through it. The caller must
 * hold the permission of every picked component, checked before anything is created. Everything is created
 * in one transaction, so a component that fails leaves nothing behind. Items the company already has are
 * skipped and reported, never overwritten.
 *
 * @phpstan-import-type Pack from TemplatePacks
 */
final class InstallTemplatePack
{
    public function __construct(
        private readonly TemplateComponents $components,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  Pack  $pack
     * @param  list<string>  $componentIds  ids of the components to install
     * @param  array<string, list<string>>  $exclude  item keys to leave out, by component id
     * @return list<array{id: string, template: string, created: list<string>, skipped: list<string>}>
     *
     * @throws ValidationException a component or item the pack lacks, or one a module refuses
     * @throws AuthorizationException the caller lacks the permission of a picked component
     */
    public function __invoke(array $pack, array $componentIds, array $exclude, AccessContext $access, int $actorId): array
    {
        $entries = array_column($pack['components'], null, 'id');
        $errors = [];

        foreach ($componentIds as $index => $id) {
            if (! isset($entries[$id])) {
                $errors["components.{$index}"] = [__('template_packs.component_unknown', ['id' => $id])];
            }
        }

        foreach (array_keys($exclude) as $id) {
            if (! in_array($id, $componentIds, true)) {
                $errors["exclude.{$id}"] = [__('template_packs.exclude_not_picked', ['id' => $id])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // The pack lists the components in install order, whatever order the caller named them in.
        $picked = array_values(array_filter($pack['components'], fn (array $entry): bool => in_array($entry['id'], $componentIds, true)));

        foreach ($picked as $entry) {
            $access->requirePermission($this->components->for($entry['kind'])->permission());
        }

        return DB::transaction(function () use ($pack, $picked, $exclude, $actorId): array {
            $results = [];

            foreach ($picked as $entry) {
                $component = $this->components->for($entry['kind']);
                $preview = $component->preview($entry['template']);
                $known = array_map(fn ($item): string => $item->key, $preview->items);

                foreach ($exclude[$entry['id']] ?? [] as $key) {
                    if (! in_array($key, $known, true)) {
                        throw ValidationException::withMessages(["exclude.{$entry['id']}" => [__('template_packs.item_unknown', ['key' => $key, 'id' => $entry['id']])]]);
                    }
                }

                $left = $exclude[$entry['id']] ?? [];
                $create = [];
                $skipped = [];

                foreach ($preview->items as $item) {
                    if ($item->exists) {
                        $skipped[] = $item->key;
                    } elseif ($item->conflict !== null) {
                        // Something else of the company stands in the way; only the caller may decide to leave it out.
                        if (! in_array($item->key, $left, true)) {
                            throw ValidationException::withMessages(["exclude.{$entry['id']}" => [__('template_packs.item_conflict', ['key' => $item->key, 'id' => $entry['id'], 'reason' => $item->conflict])]]);
                        }

                        $skipped[] = $item->key;
                    } elseif (! in_array($item->key, $left, true)) {
                        $create[] = $item->key;
                    }
                }

                if ($create !== []) {
                    $component->install($entry['template'], $create, $actorId);
                }

                $results[] = ['id' => $entry['id'], 'template' => $entry['template'], 'created' => $create, 'skipped' => $skipped];
            }

            $this->audit->record('template_pack.installed', "Template pack {$pack['key']} installed.", properties: [
                'pack' => $pack['key'],
                'version' => $pack['version'],
                'components' => array_map(fn (array $result): array => ['id' => $result['id'], 'template' => $result['template'], 'created' => count($result['created']), 'skipped' => count($result['skipped'])], $results),
            ]);

            return $results;
        });
    }
}
