<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * What a group grant lets a user do (IA-FR-07): read the group's reports, run consolidations and change the
 * group, or approve the control assessment of its members (CO-FR-02). Running and approving include reading;
 * neither includes the other, so the approval can rest with someone who does not prepare the group.
 */
enum GroupAbility: string
{
    case Read = 'read';
    case Run = 'run';
    case Approve = 'approve';

    /**
     * The stored abilities that include this one.
     *
     * @return list<string>
     */
    public function coveredBy(): array
    {
        return match ($this) {
            self::Read => ['read', 'run', 'approve'],
            self::Run => ['run'],
            self::Approve => ['approve'],
        };
    }
}
