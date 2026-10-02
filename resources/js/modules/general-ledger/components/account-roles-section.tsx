import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    allAccountsQuery,
    type Account,
} from '@/modules/general-ledger/accounts';
import {
    accountRolesQuery,
    mapAccountRole,
    type AccountRoleRow,
} from '@/modules/general-ledger/ledger-settings';

function RoleRow({
    companyId,
    role,
    accounts,
    canManage,
}: {
    companyId: string;
    role: AccountRoleRow;
    accounts: Account[];
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [chosen, setChosen] = useState(role.account_id ?? '');

    // An account takes a role when it is an active leaf of one of the classes the role accepts (GL-FR-10).
    const items = accounts
        .filter(
            (account) =>
                account.status === 'active' &&
                account.is_leaf &&
                (role.allowed_classes as string[]).includes(account.class),
        )
        .map((account) => ({
            value: account.id,
            label: `${account.code} · ${account.name}`,
        }));

    const save = useMutation({
        mutationFn: () =>
            mapAccountRole(companyId, role.key, chosen, role.lock_version),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'account-roles'],
            });
            toast.success(
                t('ledgerSettings.roles.saved', {
                    role: t(`ledgerSettings.roles.names.${role.key}`),
                }),
            );
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await queryClient.invalidateQueries({
                    queryKey: ['companies', companyId, 'account-roles'],
                });
                toast.error(t('ledgerSettings.roles.conflict'));

                return;
            }

            toast.error(actionErrorMessage(error, t));
        },
    });

    const status =
        role.account_id === null
            ? 'unmapped'
            : role.valid
              ? 'mapped'
              : 'invalid';

    return (
        <TableRow>
            <TableCell className="align-top">
                <div className="font-medium">
                    {t(`ledgerSettings.roles.names.${role.key}`)}
                </div>
                <div className="text-xs text-muted-foreground">
                    {t(`ledgerSettings.roles.needed.${role.required}`)}
                </div>
            </TableCell>
            <TableCell className="min-w-64 align-top">
                <SearchableSelect
                    id={`role-${role.key}`}
                    aria-label={t(`ledgerSettings.roles.names.${role.key}`)}
                    items={items}
                    value={chosen}
                    onValueChange={setChosen}
                    placeholder={t('ledgerSettings.roles.choose')}
                    emptyText={t('common.noMatch')}
                    disabled={!canManage}
                />
            </TableCell>
            <TableCell className="align-top">
                <Badge
                    variant={
                        status === 'mapped'
                            ? 'default'
                            : status === 'invalid'
                              ? 'destructive'
                              : 'outline'
                    }
                >
                    {t(`ledgerSettings.roles.status.${status}`)}
                </Badge>
            </TableCell>
            {canManage && (
                <TableCell className="text-end align-top">
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label={t('ledgerSettings.roles.save', {
                            role: t(`ledgerSettings.roles.names.${role.key}`),
                        })}
                        disabled={
                            save.isPending ||
                            chosen === '' ||
                            chosen === role.account_id
                        }
                        onClick={() => save.mutate()}
                    >
                        {t('common.save')}
                    </Button>
                </TableCell>
            )}
        </TableRow>
    );
}

/**
 * Which account plays each ledger role (GL-FR-10): retained earnings for closing the year, and the
 * accounts for exchange differences once the company uses foreign currencies. Each change is a new
 * version of the mapping.
 */
export function AccountRolesSection({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const roles = useQuery(accountRolesQuery(companyId));
    const accounts = useQuery(allAccountsQuery(companyId));

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <h2 className="text-base font-semibold">
                    {t('ledgerSettings.roles.title')}
                </h2>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('ledgerSettings.roles.description')}
                </p>
            </div>

            {roles.isError || accounts.isError ? (
                <FormAlert>
                    {genericErrorMessage(roles.error ?? accounts.error, t)}
                </FormAlert>
            ) : roles.data === undefined || accounts.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('ledgerSettings.roles.role')}
                                </TableHead>
                                <TableHead>
                                    {t('ledgerSettings.roles.account')}
                                </TableHead>
                                <TableHead>
                                    {t('ledgerSettings.roles.statusLabel')}
                                </TableHead>
                                {canManage && (
                                    <TableHead className="text-end">
                                        {t('ledgerSettings.roles.actions')}
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roles.data.map((role) => (
                                <RoleRow
                                    // A new version resets what was chosen in the row.
                                    key={`${role.key}:${role.lock_version}:${role.account_id}`}
                                    companyId={companyId}
                                    role={role}
                                    accounts={accounts.data}
                                    canManage={canManage}
                                />
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </section>
    );
}
