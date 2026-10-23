import { useQuery } from '@tanstack/react-query';
import { ListTreeIcon, PencilIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { genericErrorMessage } from '@/lib/errors';
import { GroupAccountDialog } from '@/modules/consolidation/components/group-account-dialog';
import {
    groupAccountsQuery,
    groupCan,
    type GroupAccount,
} from '@/modules/consolidation/groups';
import { useGroup } from '@/modules/consolidation/hooks/use-group';

/** The chart of accounts of the group, which the accounts of its companies are mapped to (CO-FR-04). */
export function GroupAccountsPage({ groupId }: { groupId: string }) {
    const { t } = useTranslation();
    const group = useGroup(groupId);
    const accounts = useQuery(groupAccountsQuery(groupId));
    const [dialog, setDialog] = useState<{ account?: GroupAccount } | null>(
        null,
    );
    const canRun = groupCan(group, 'run');

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('consolidation.accounts.description')}
                </p>
                {canRun && (
                    <Button onClick={() => setDialog({})}>
                        <PlusIcon data-icon="inline-start" />
                        {t('consolidation.accounts.new')}
                    </Button>
                )}
            </div>

            {accounts.isError ? (
                <FormAlert>{genericErrorMessage(accounts.error, t)}</FormAlert>
            ) : accounts.data === undefined ? (
                <Skeleton className="h-32 w-full" />
            ) : accounts.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ListTreeIcon />
                        </EmptyMedia>
                        <EmptyTitle>
                            {t('consolidation.accounts.empty')}
                        </EmptyTitle>
                        <EmptyDescription>
                            {t('consolidation.accounts.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('consolidation.accounts.code')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.accounts.name')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.accounts.class')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.accounts.cashFlowClass')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.accounts.status')}
                                </TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {accounts.data.map((account) => (
                                <TableRow key={account.id}>
                                    <TableCell className="font-mono">
                                        {account.code}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {account.name}
                                    </TableCell>
                                    <TableCell>
                                        {t(
                                            `consolidation.classes.${account.class as 'asset'}`,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {account.cash_flow_class
                                            ? t(
                                                  `consolidation.cashFlowClasses.${account.cash_flow_class as 'cash'}`,
                                              )
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                account.is_active
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {t(
                                                account.is_active
                                                    ? 'consolidation.accounts.activeStatus'
                                                    : 'consolidation.accounts.inactiveStatus',
                                            )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        {canRun && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                aria-label={t(
                                                    'common.actionsFor',
                                                    { name: account.name },
                                                )}
                                                onClick={() =>
                                                    setDialog({ account })
                                                }
                                            >
                                                <PencilIcon data-icon="inline-start" />
                                                {t(
                                                    'consolidation.accounts.edit',
                                                )}
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <GroupAccountDialog
                groupId={groupId}
                open={dialog !== null}
                account={dialog?.account}
                onOpenChange={(open) => {
                    if (!open) {
                        setDialog(null);
                    }
                }}
            />
        </>
    );
}
