import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ListTreeIcon, PlusIcon, SearchIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { TablePagination } from '@/components/table-pagination';
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
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
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
import { useLastDefined } from '@/hooks/use-last-defined';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { ALL, usePageSize } from '@/lib/page-size';
import {
    ACCOUNT_CLASSES,
    accountDepths,
    allAccountsQuery,
    setAccountStatus,
    type Account,
    type AccountClass,
    type AccountStatus,
} from '@/modules/general-ledger/accounts';
import { AccountFormDialog } from '@/modules/general-ledger/components/account-form-dialog';

const ANY = '__any';

/**
 * Chart of accounts (GL-FR-01): the whole tree, indented by level, filterable and pageable in the
 * browser, with add, edit, deactivate and reactivate. Deactivating asks first.
 */
export function AccountsPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [accountClass, setAccountClass] = useState<
        AccountClass | undefined
    >();
    const [status, setStatus] = useState<AccountStatus | undefined>();
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('accounts', {
        fallback: 50,
        allowAll: true,
    });
    const [editing, setEditing] = useState<Account | null>(null);
    const [creating, setCreating] = useState(false);
    const [deactivating, setDeactivating] = useState<Account | null>(null);
    const asking = useLastDefined(deactivating);

    useDocumentTitle(t('accounts.pageTitle'));

    const chart = useQuery(allAccountsQuery(companyId));
    const accounts = chart.data;
    // Depth comes from the whole chart, so a filtered view keeps every account at its own level.
    const depths = useMemo(() => accountDepths(accounts ?? []), [accounts]);
    const needle = search.trim().toLowerCase();
    const matching = (accounts ?? []).filter(
        (account) =>
            (!accountClass || account.class === accountClass) &&
            (!status || account.status === status) &&
            (!needle ||
                account.code.toLowerCase().startsWith(needle) ||
                account.name.toLowerCase().includes(needle)),
    );
    const lastPage =
        pageSize === ALL
            ? 1
            : Math.max(1, Math.ceil(matching.length / pageSize));
    const current = Math.min(page, lastPage);
    const shown =
        pageSize === ALL
            ? matching
            : matching.slice((current - 1) * pageSize, current * pageSize);

    const toggle = useMutation({
        mutationFn: (account: Account) =>
            setAccountStatus(
                companyId,
                account.id,
                account.status === 'active' ? 'deactivate' : 'reactivate',
            ),
        onSuccess: async (account) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'accounts'],
            });
            toast.success(
                t(
                    account.status === 'active'
                        ? 'accounts.reactivated'
                        : 'accounts.deactivated',
                    { code: account.code },
                ),
            );
        },
        // The server says exactly why (active children, inactive parent), already translated.
        onError: (error) =>
            toast.error(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : genericErrorMessage(error, t),
            ),
        onSettled: () => setDeactivating(null),
    });

    const classItems = [
        { value: ANY, label: t('accounts.allClasses') },
        ...ACCOUNT_CLASSES.map((value) => ({
            value,
            label: t(`accountClass.${value}`),
        })),
    ];
    const statusItems = [
        { value: ANY, label: t('accounts.allStatuses') },
        { value: 'active', label: t('accounts.active') },
        { value: 'inactive', label: t('accounts.inactive') },
    ];

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('accounts.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('accounts.description')}
                    </p>
                </div>
                {canManage && (
                    <Button onClick={() => setCreating(true)}>
                        <PlusIcon data-icon="inline-start" />
                        {t('accounts.add')}
                    </Button>
                )}
            </div>

            <div className="flex flex-wrap gap-3">
                <InputGroup className="w-full sm:w-72">
                    <InputGroupAddon>
                        <SearchIcon aria-hidden />
                    </InputGroupAddon>
                    <InputGroupInput
                        type="search"
                        value={search}
                        placeholder={t('accounts.search')}
                        aria-label={t('accounts.search')}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                    />
                </InputGroup>
                <Select
                    value={accountClass ?? ANY}
                    items={classItems}
                    onValueChange={(value) => {
                        setAccountClass(
                            value === ANY ? undefined : (value as AccountClass),
                        );
                        setPage(1);
                    }}
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label={t('accounts.class')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {classItems.map((item) => (
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
                    onValueChange={(value) => {
                        setStatus(
                            value === ANY
                                ? undefined
                                : (value as AccountStatus),
                        );
                        setPage(1);
                    }}
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label={t('accounts.status')}
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

            {chart.isError ? (
                <FormAlert>{genericErrorMessage(chart.error, t)}</FormAlert>
            ) : accounts === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : accounts.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ListTreeIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('accounts.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('accounts.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && (
                        <EmptyContent>
                            <Button onClick={() => setCreating(true)}>
                                <PlusIcon data-icon="inline-start" />
                                {t('accounts.add')}
                            </Button>
                        </EmptyContent>
                    )}
                </Empty>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-28">
                                        {t('accounts.code')}
                                    </TableHead>
                                    <TableHead>
                                        {t('accounts.accountName')}
                                    </TableHead>
                                    <TableHead>{t('accounts.class')}</TableHead>
                                    <TableHead>
                                        {t('accounts.normal')}
                                    </TableHead>
                                    <TableHead>
                                        {t('accounts.status')}
                                    </TableHead>
                                    {canManage && (
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                {t('accounts.actions')}
                                            </span>
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shown.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={canManage ? 6 : 5}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            {t('accounts.noMatch')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {shown.map((account) => (
                                    <TableRow
                                        key={account.id}
                                        className={
                                            account.status === 'active'
                                                ? undefined
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <TableCell className="font-mono text-xs">
                                            {account.code}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={
                                                    account.is_leaf
                                                        ? undefined
                                                        : 'font-medium'
                                                }
                                                style={{
                                                    paddingInlineStart: `${(depths.get(account.id) ?? 0) * 20}px`,
                                                }}
                                            >
                                                {account.name}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {t(
                                                `accountClass.${account.class}`,
                                                {
                                                    defaultValue: account.class,
                                                },
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {t(
                                                `normalBalance.${account.normal_balance}`,
                                                {
                                                    defaultValue:
                                                        account.normal_balance,
                                                },
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    account.status === 'active'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {t(
                                                    `accounts.${account.status}`,
                                                    {
                                                        defaultValue:
                                                            account.status,
                                                    },
                                                )}
                                            </Badge>
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-end">
                                                <RowActions
                                                    label={account.code}
                                                >
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setEditing(account)
                                                        }
                                                    >
                                                        {t(
                                                            'accounts.editAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                    {account.status ===
                                                    'active' ? (
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onClick={() =>
                                                                setDeactivating(
                                                                    account,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'accounts.deactivateAction',
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
                                                                'accounts.reactivateAction',
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

                    <TablePagination
                        total={matching.length}
                        page={current}
                        pageSize={pageSize}
                        allowAll
                        onPageChange={setPage}
                        onPageSizeChange={(size) => {
                            setPageSize(size);
                            setPage(1);
                        }}
                    />
                </div>
            )}

            <AccountFormDialog
                companyId={companyId}
                open={creating}
                onOpenChange={setCreating}
            />
            <AccountFormDialog
                companyId={companyId}
                account={editing ?? undefined}
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
                title={t('confirm.deactivateAccount.title', {
                    code: asking?.code ?? '',
                })}
                description={t('confirm.deactivateAccount.description')}
                confirmLabel={t('confirm.deactivateAccount.action')}
                pending={toggle.isPending}
                destructive
                onConfirm={() => deactivating && toggle.mutate(deactivating)}
            />
        </>
    );
}
