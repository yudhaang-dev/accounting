import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LandmarkIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useLastDefined } from '@/hooks/use-last-defined';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { allAccountsQuery } from '@/modules/general-ledger/accounts';
import { CashAccountDialog } from '@/modules/subledger/components/cash-account-dialog';
import {
    cashAccountsQuery,
    setCashAccountStatus,
    type CashAccount,
} from '@/modules/subledger/cash';

const ANY = '__any';

/**
 * Cash boxes and bank accounts (SL-FR-03). Each has one currency and one ledger account that
 * accepts the subledger source only; neither changes after the account is added. An account is
 * deactivated, never deleted, so its transactions stay readable.
 */
export function CashAccountsPage({
    companyId,
    canManage,
    canReadAccounts,
}: {
    companyId: string;
    canManage: boolean;
    /** Whether the user may list the chart, to name the ledger account of each row. */
    canReadAccounts: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [kind, setKind] = useState<'cash' | 'bank' | undefined>();
    const [status, setStatus] = useState<'active' | 'inactive' | undefined>();
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<CashAccount | null>(null);
    const [deactivating, setDeactivating] = useState<CashAccount | null>(null);
    const asking = useLastDefined(deactivating);

    useDocumentTitle(t('cashAccounts.pageTitle'));

    const cashAccounts = useQuery(cashAccountsQuery(companyId));
    const accounts = useQuery({
        ...allAccountsQuery(companyId),
        enabled: canReadAccounts,
    });
    const ledgerName = new Map(
        (accounts.data ?? []).map((account) => [
            account.id,
            `${account.code} · ${account.name}`,
        ]),
    );

    const kindItems = [
        { value: ANY, label: t('cashAccounts.allKinds') },
        { value: 'bank', label: t('cashAccounts.kind.bank') },
        { value: 'cash', label: t('cashAccounts.kind.cash') },
    ];
    const statusItems = [
        { value: ANY, label: t('cashAccounts.allStatuses') },
        { value: 'active', label: t('cashAccounts.active') },
        { value: 'inactive', label: t('cashAccounts.inactive') },
    ];
    const rows = (cashAccounts.data ?? []).filter(
        (row) =>
            (kind === undefined || row.kind === kind) &&
            (status === undefined || row.status === status),
    );
    const filtered = kind !== undefined || status !== undefined;

    const toggle = useMutation({
        mutationFn: (account: CashAccount) =>
            setCashAccountStatus(
                companyId,
                account.id,
                account.status === 'active' ? 'deactivate' : 'reactivate',
            ),
        onSuccess: async (account) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'cash-accounts'],
            });
            toast.success(
                t(
                    account.status === 'active'
                        ? 'cashAccounts.reactivated'
                        : 'cashAccounts.deactivated',
                    { name: account.name },
                ),
            );
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setDeactivating(null),
    });

    const addButton = (
        <Button onClick={() => setCreating(true)}>
            <PlusIcon data-icon="inline-start" />
            {t('cashAccounts.add')}
        </Button>
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('cashAccounts.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('cashAccounts.description')}
                    </p>
                </div>
                {canManage && addButton}
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <Select
                    value={kind ?? ANY}
                    items={kindItems}
                    onValueChange={(value) =>
                        setKind(
                            value === 'cash' || value === 'bank'
                                ? value
                                : undefined,
                        )
                    }
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label={t('cashAccounts.kindLabel')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {kindItems.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <Select
                    value={status ?? ANY}
                    items={statusItems}
                    onValueChange={(value) =>
                        setStatus(
                            value === 'active' || value === 'inactive'
                                ? value
                                : undefined,
                        )
                    }
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label={t('cashAccounts.status')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {statusItems.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>

            {cashAccounts.isError ? (
                <FormAlert>
                    {genericErrorMessage(cashAccounts.error, t)}
                </FormAlert>
            ) : cashAccounts.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : rows.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <LandmarkIcon />
                        </EmptyMedia>
                        <EmptyTitle>
                            {t(
                                filtered
                                    ? 'cashAccounts.noMatch'
                                    : 'cashAccounts.empty',
                            )}
                        </EmptyTitle>
                        <EmptyDescription>
                            {t(
                                filtered
                                    ? 'cashAccounts.noMatchDescription'
                                    : 'cashAccounts.emptyDescription',
                            )}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && !filtered && (
                        <EmptyContent>{addButton}</EmptyContent>
                    )}
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('cashAccounts.name')}</TableHead>
                                <TableHead>
                                    {t('cashAccounts.kindLabel')}
                                </TableHead>
                                <TableHead>
                                    {t('cashAccounts.bankDetails')}
                                </TableHead>
                                <TableHead>
                                    {t('cashAccounts.currency')}
                                </TableHead>
                                {canReadAccounts && (
                                    <TableHead>
                                        {t('cashAccounts.ledgerAccount')}
                                    </TableHead>
                                )}
                                <TableHead>
                                    {t('cashAccounts.status')}
                                </TableHead>
                                {canManage && (
                                    <TableHead className="w-12">
                                        <span className="sr-only">
                                            {t('cashAccounts.actions')}
                                        </span>
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((account) => (
                                <TableRow
                                    key={account.id}
                                    className={
                                        account.status === 'active'
                                            ? undefined
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <TableCell className="font-medium">
                                        {account.name}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="secondary">
                                            {t(
                                                account.kind === 'cash'
                                                    ? 'cashAccounts.kind.cash'
                                                    : 'cashAccounts.kind.bank',
                                            )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        {account.bank_name === null ? (
                                            '—'
                                        ) : (
                                            <>
                                                {account.bank_name}
                                                <span className="ms-2 font-mono text-xs text-muted-foreground">
                                                    {account.account_number}
                                                </span>
                                            </>
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {account.currency}
                                    </TableCell>
                                    {canReadAccounts && (
                                        <TableCell>
                                            {ledgerName.get(
                                                account.account_id,
                                            ) ?? '—'}
                                        </TableCell>
                                    )}
                                    <TableCell>
                                        <Badge
                                            variant={
                                                account.status === 'active'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {t(
                                                account.status === 'active'
                                                    ? 'cashAccounts.active'
                                                    : 'cashAccounts.inactive',
                                            )}
                                        </Badge>
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-end">
                                            <RowActions label={account.name}>
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        setEditing(account)
                                                    }
                                                >
                                                    {t(
                                                        'cashAccounts.editAction',
                                                    )}
                                                </DropdownMenuItem>
                                                {account.status === 'active' ? (
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() =>
                                                            setDeactivating(
                                                                account,
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'cashAccounts.deactivateAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                ) : (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            toggle.mutate(
                                                                account,
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'cashAccounts.reactivateAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                )}
                                            </RowActions>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <CashAccountDialog
                companyId={companyId}
                open={creating}
                onOpenChange={setCreating}
            />
            <CashAccountDialog
                companyId={companyId}
                cashAccount={editing ?? undefined}
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            />
            <ConfirmDialog
                open={deactivating !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeactivating(null);
                    }
                }}
                title={t('confirm.deactivateCashAccount.title', {
                    name: asking?.name ?? '',
                })}
                description={t('confirm.deactivateCashAccount.description')}
                confirmLabel={t('confirm.deactivateCashAccount.action')}
                pending={toggle.isPending}
                destructive
                onConfirm={() => deactivating && toggle.mutate(deactivating)}
            />
        </>
    );
}
