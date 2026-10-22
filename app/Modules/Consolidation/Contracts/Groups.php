<?php

namespace App\Modules\Consolidation\Contracts;

/**
 * Groups and their perimeter for the application layer. Whether a user may create a group, or put a company
 * into one, is decided there: it holds the company's consent and the grants of the group (IA-FR-07), which
 * this module does not know.
 */
interface Groups
{
    /**
     * Creates a group with its parent company as the first member, approved by its creator (CO-FR-02): the
     * parent needs no assessment of control over itself. A currency or company that does not exist fails
     * validation.
     */
    public function create(string $name, string $parentCompanyId, string $presentationCurrency, int $createdBy): GroupData;

    /** The group, or null when there is none with that ID. */
    public function find(string $groupId): ?GroupData;

    /**
     * @param  list<string>  $groupIds
     * @return list<GroupData> by name
     */
    public function findMany(array $groupIds): array;

    /**
     * Proposes a company as a member, with the reasoning behind the assessment that the group controls it. It
     * counts only once someone with the approval ability, other than the proposer, approves it.
     */
    public function proposeMember(string $groupId, string $companyId, string $controlBasis, string $effectiveFrom, int $proposedBy): PerimeterMemberData;
}
