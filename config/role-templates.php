<?php

/*
| Seed roles for a new company (IA-FR-02). These are data, not behaviour: after seeding, a company
| may change, rename or add roles, and code only ever checks permission keys (P-01). Every key must
| exist in PermissionCatalog. `grant_to_creator` roles are assigned to the user who creates the
| workspace so that the first user can set the company up and work in it.
*/

$read = ['company.read', 'accounts.read', 'periods.read', 'entries.read', 'journals.read', 'reports.read'];

return [

    'templates' => [
        [
            'name' => 'accounting_admin',
            'description' => 'Configuration, memberships, roles and integration clients.',
            'grant_to_creator' => true,
            'permissions' => [
                ...$read,
                'company.update', 'company.activate', 'company.deactivate',
                'accounts.manage',
                'periods.manage', 'periods.close', 'periods.reopen',
                'memberships.read', 'memberships.manage',
                'roles.read', 'roles.manage',
                'integration-clients.manage',
                'policies.manage',
                'reports.export',
            ],
        ],
        [
            'name' => 'preparer',
            'description' => 'Creates and submits entries.',
            'grant_to_creator' => true,
            'permissions' => [...$read, 'entries.create', 'entries.submit', 'entries.reverse'],
        ],
        [
            'name' => 'approver',
            'description' => 'Approves or rejects entries and reversals.',
            'grant_to_creator' => true,
            'permissions' => [...$read, 'entries.approve', 'entries.reject', 'entries.post'],
        ],
        [
            'name' => 'viewer',
            'description' => 'Reads reports.',
            'grant_to_creator' => false,
            'permissions' => $read,
        ],
        [
            'name' => 'auditor',
            'description' => 'Reads reports, drill-down and the audit log.',
            'grant_to_creator' => false,
            'permissions' => [...$read, 'reports.export', 'audit.read'],
        ],
    ],

];
