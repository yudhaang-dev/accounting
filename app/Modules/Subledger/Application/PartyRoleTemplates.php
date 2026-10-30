<?php

namespace App\Modules\Subledger\Application;

/**
 * The roles a company may adopt for its parties (SL-FR-01, ADR-0015). Only a suggestion until the company
 * confirms the ones it wants (X-AC-03).
 */
final class PartyRoleTemplates
{
    public const KEYS = ['customer', 'supplier', 'employee', 'lender', 'shareholder'];

    /**
     * @return list<array{key: string, label: string}> labels in the language of the request
     */
    public function all(): array
    {
        return array_map(fn (string $key): array => ['key' => $key, 'label' => __("party_roles.template.{$key}")], self::KEYS);
    }
}
