<?php

namespace App\Orchestration\Setup;

use LogicException;

/**
 * Template packs shipped as manifest files (ADR-0019). A manifest only names the template of each module
 * by key; it holds no content of its own and is not company data. Components are listed in the order they
 * must be installed: accounts, account posting attributes, party roles, account roles, posting profiles, cash equivalents, transaction types, report
 * definitions.
 *
 * @phpstan-type PackComponent array{id: string, kind: string, template: string}
 * @phpstan-type Pack array{key: string, name: string, description: string, version: int, reviewed: bool, additive?: bool, components: list<PackComponent>, profiles?: array<string, array{components: list<string>, keep?: array<string, list<string>>}>}
 */
final class TemplatePacks
{
    /** The install order of the kinds; a manifest that lists them differently is a programming error. */
    public const KIND_ORDER = ['chart_of_accounts', 'account_posting', 'party_roles', 'account_roles', 'posting_profiles', 'cash_equivalents', 'transaction_types', 'report_definitions'];

    /** @var array<string, Pack>|null */
    private ?array $packs = null;

    /**
     * The packs released for use: a pack not yet reviewed by an accountant is left out (ADR-0019).
     *
     * @return array<string, Pack>
     */
    public function all(): array
    {
        return array_filter($this->load(), fn (array $pack): bool => $pack['reviewed']);
    }

    /** @return Pack|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The components a profile of the pack installs, in install order (ADR-0019 item 6).
     *
     * @param  Pack  $pack
     * @return list<PackComponent>|null null when the pack has no such profile
     */
    public function profileComponents(array $pack, string $profile): ?array
    {
        $ids = $pack['profiles'][$profile]['components'] ?? null;

        return $ids === null ? null : array_values(array_filter($pack['components'], fn (array $entry): bool => in_array($entry['id'], $ids, true)));
    }

    /** @return array<string, Pack> */
    private function load(): array
    {
        if ($this->packs !== null) {
            return $this->packs;
        }

        $this->packs = [];

        foreach (glob(rtrim((string) config('templates.packs_path'), '/\\').'/*.php') ?: [] as $file) {
            /** @var Pack $pack */
            $pack = require $file;
            $this->assertValid($pack, $file);
            $this->packs[$pack['key']] = $pack;
        }

        ksort($this->packs);

        return $this->packs;
    }

    /**
     * @param  Pack  $pack
     *
     * @throws LogicException a component of an unknown kind, listed out of order, or with a repeated id
     */
    private function assertValid(array $pack, string $file): void
    {
        $ids = [];
        $last = -1;

        foreach ($pack['components'] as $component) {
            $position = array_search($component['kind'], self::KIND_ORDER, true);

            if ($position === false || $position < $last) {
                throw new LogicException("Template pack {$pack['key']} ({$file}): component {$component['id']} has an unknown kind or is out of order.");
            }

            if (isset($ids[$component['id']])) {
                throw new LogicException("Template pack {$pack['key']} ({$file}): component id {$component['id']} is repeated.");
            }

            $ids[$component['id']] = true;
            $last = $position;
        }

        foreach ($pack['profiles'] ?? [] as $name => $profile) {
            foreach ($profile['components'] as $id) {
                if (! isset($ids[$id])) {
                    throw new LogicException("Template pack {$pack['key']} ({$file}): profile {$name} names unknown component {$id}.");
                }
            }
        }
    }
}
