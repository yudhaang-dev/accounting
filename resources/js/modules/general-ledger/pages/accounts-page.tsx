import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ListTreeIcon, PlusIcon, SearchIcon } from 'lucide-react';
import { useDeferredValue, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    ACCOUNT_CLASSES,
    accountsQuery,
    setAccountStatus,
    type Account,
    type AccountClass,
    type AccountStatus,
} from '@/modules/general-ledger/accounts';
import { AccountFormDialog } from '@/modules/general-ledger/components/account-form-dialog';

const ALL = '__all';

/** Chart of accounts (GL-FR-01): browse, filter, add, edit, deactivate and reactivate. */
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
    const [editing, setEditing] = useState<Account | null>(null);
    const [creating, setCreating] = useState(false);
    const deferredSearch = useDeferredValue(search.trim());

    useDocumentTitle(t('accounts.pageTitle'));

    const filters = {
        class: accountClass,
        status,
        search: deferredSearch,
        page,
    };
    const list = useQuery(accountsQuery(companyId, filters));
    const filtered = Boolean(accountClass || status || deferredSearch);

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
    });

    const classItems = [
        { value: ALL, label: t('accounts.allClasses') },
        ...ACCOUNT_CLASSES.map((value) => ({
            value,
            label: t(`accountClass.${value}`),
        })),
    ];
    const statusItems = [
        { value: ALL, label: t('accounts.allStatuses') },
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
                    value={accountClass ?? ALL}
                    items={classItems}
                    onValueChange={(value) => {
                        setAccountClass(
                            value === ALL ? undefined : (value as AccountClass),
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
                    value={status ?? ALL}
                    items={statusItems}
                    onValueChange={(value) => {
                        setStatus(
                            value === ALL
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

            {list.isError ? (
                <FormAlert>{genericErrorMessage(list.error, t)}</FormAlert>
            ) : list.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : list.data.total === 0 && !filtered ? (
                <Empty className="max-w-xl border">
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
                                    <TableHead>{t('accounts.code')}</TableHead>
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
                                        <TableHead className="text-end">
                                            {t('accounts.actions')}
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.data.accounts.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={canManage ? 6 : 5}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            {t('accounts.noMatch')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {list.data.accounts.map((account) => (
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
                                            {account.name}
                                            {!account.is_leaf && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    {t('accounts.parentBadge')}
                                                </Badge>
                                            )}
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
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={t(
                                                            'accounts.edit',
                                                            {
                                                                code: account.code,
                                                            },
                                                        )}
                                                        onClick={() =>
                                                            setEditing(account)
                                                        }
                                                    >
                                                        {t(
                                                            'accounts.editAction',
                                                        )}
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={
                                                            toggle.isPending
                                                        }
                                                        aria-label={t(
                                                            account.status ===
                                                                'active'
                                                                ? 'accounts.deactivate'
                                                                : 'accounts.reactivate',
                                                            {
                                                                code: account.code,
                                                            },
                                                        )}
                                                        onClick={() =>
                                                            toggle.mutate(
                                                                account,
                                                            )
                                                        }
                                                    >
                                                        {account.status ===
                                                        'active'
                                                            ? t(
                                                                  'accounts.deactivateAction',
                                                              )
                                                            : t(
                                                                  'accounts.reactivateAction',
                                                              )}
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                        <span aria-live="polite">
                            {t('accounts.count', { count: list.data.total })}
                        </span>
                        {list.data.lastPage > 1 && (
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={list.data.page <= 1}
                                    onClick={() => setPage(list.data.page - 1)}
                                >
                                    {t('accounts.previous')}
                                </Button>
                                <span>
                                    {t('accounts.pageOf', {
                                        page: list.data.page,
                                        last: list.data.lastPage,
                                    })}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        list.data.page >= list.data.lastPage
                                    }
                                    onClick={() => setPage(list.data.page + 1)}
                                >
                                    {t('accounts.next')}
                                </Button>
                            </div>
                        )}
                    </div>
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
        </>
    );
}
