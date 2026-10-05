import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ArrowRightLeftIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { RowActions } from '@/components/row-actions';
import { SubmitButton } from '@/components/submit-button';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
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
import { todayIso } from '@/lib/date';
import { formatDecimal } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import {
    cashAccountsQuery,
    cashTransactionsQuery,
    clearCashTransaction,
    unclearCashTransaction,
    type CashTransaction,
} from '@/modules/subledger/cash';

const ANY = '__any';

function ClearDialog({
    companyId,
    transaction,
    onOpenChange,
}: {
    companyId: string;
    transaction: CashTransaction | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const shown = useLastDefined(transaction);
    const [clearedOn, setClearedOn] = useState(todayIso());
    const [formError, setFormError] = useState<string | null>(null);

    const clear = useMutation({
        mutationFn: (id: string) =>
            clearCashTransaction(companyId, id, clearedOn || null),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'cash-transactions'],
            });
            toast.success(t('cashTransactions.clearedDone'));
            onOpenChange(false);
        },
        onError: (error) => setFormError(actionErrorMessage(error, t)),
    });

    return (
        <FormDialog
            open={transaction !== null}
            onOpenChange={onOpenChange}
            title={t('cashTransactions.clearTitle')}
            description={t('cashTransactions.clearDescription')}
        >
            <form
                noValidate
                className="flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    setFormError(null);

                    if (shown) {
                        clear.mutate(shown.id);
                    }
                }}
            >
                {formError && <FormAlert>{formError}</FormAlert>}
                <Field>
                    <FieldLabel id="clear-date-label">
                        {t('cashTransactions.clearedOn')}
                    </FieldLabel>
                    <DatePicker
                        labelledBy="clear-date-label"
                        value={clearedOn}
                        onChange={setClearedOn}
                    />
                </Field>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={clear.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('cashTransactions.markCleared')}
                    </SubmitButton>
                </DialogFooter>
            </form>
        </FormDialog>
    );
}

/**
 * Receipts and payments on the cash and bank accounts (SL-FR-11). Documents record them; here a
 * user with the permission marks one cleared once the bank or the cash count confirms it, and can
 * take the mark off. The amount is always in the currency of the account.
 */
export function CashTransactionsPage({
    companyId,
    canClear,
    canReadJournals,
}: {
    companyId: string;
    canClear: boolean;
    /** Whether the user may open a journal, to link the transfers that have one. */
    canReadJournals: boolean;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [accountId, setAccountId] = useState<string | undefined>();
    const [cleared, setCleared] = useState<boolean | undefined>();
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('cash-transactions');
    const [clearing, setClearing] = useState<CashTransaction | null>(null);

    useDocumentTitle(t('cashTransactions.pageTitle'));

    const cashAccounts = useQuery(cashAccountsQuery(companyId));
    const transactions = useQuery(
        cashTransactionsQuery(companyId, {
            cashAccountId: accountId,
            cleared,
            dateFrom,
            dateTo,
            page,
            perPage: pageSize,
        }),
    );
    const accountName = new Map(
        (cashAccounts.data ?? []).map((account) => [account.id, account.name]),
    );

    const accountItems = [
        { value: ANY, label: t('cashTransactions.allAccounts') },
        ...(cashAccounts.data ?? []).map((account) => ({
            value: account.id,
            label: `${account.name} (${account.currency})`,
        })),
    ];
    const clearedItems = [
        { value: ANY, label: t('cashTransactions.allStates') },
        { value: 'cleared', label: t('cashTransactions.cleared') },
        { value: 'open', label: t('cashTransactions.notCleared') },
    ];
    const filtered =
        accountId !== undefined ||
        cleared !== undefined ||
        dateFrom !== '' ||
        dateTo !== '';

    const unclear = useMutation({
        mutationFn: (transaction: CashTransaction) =>
            unclearCashTransaction(companyId, transaction.id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'cash-transactions'],
            });
            toast.success(t('cashTransactions.unclearedDone'));
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
    });

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('cashTransactions.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('cashTransactions.description')}
                </p>
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <Select
                    value={accountId ?? ANY}
                    items={accountItems}
                    onValueChange={(value) => {
                        setAccountId(
                            value === ANY || value === null ? undefined : value,
                        );
                        setPage(1);
                    }}
                >
                    <SelectTrigger
                        className="w-56"
                        aria-label={t('cashTransactions.account')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {accountItems.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <Select
                    value={
                        cleared === undefined
                            ? ANY
                            : cleared
                              ? 'cleared'
                              : 'open'
                    }
                    items={clearedItems}
                    onValueChange={(value) => {
                        setCleared(
                            value === 'cleared'
                                ? true
                                : value === 'open'
                                  ? false
                                  : undefined,
                        );
                        setPage(1);
                    }}
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label={t('cashTransactions.state')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {clearedItems.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <div className="flex flex-col gap-1.5">
                    <span
                        id="cash-from-label"
                        className="text-xs text-muted-foreground"
                    >
                        {t('cashTransactions.from')}
                    </span>
                    <DatePicker
                        labelledBy="cash-from-label"
                        value={dateFrom}
                        clearable
                        placeholder={t('cashTransactions.anyDate')}
                        onChange={(value) => {
                            setDateFrom(value);
                            setPage(1);
                        }}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <span
                        id="cash-to-label"
                        className="text-xs text-muted-foreground"
                    >
                        {t('cashTransactions.to')}
                    </span>
                    <DatePicker
                        labelledBy="cash-to-label"
                        value={dateTo}
                        clearable
                        placeholder={t('cashTransactions.anyDate')}
                        onChange={(value) => {
                            setDateTo(value);
                            setPage(1);
                        }}
                    />
                </div>
            </div>

            {transactions.isError ? (
                <FormAlert>
                    {genericErrorMessage(transactions.error, t)}
                </FormAlert>
            ) : transactions.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : transactions.data.total === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ArrowRightLeftIcon />
                        </EmptyMedia>
                        <EmptyTitle>
                            {t(
                                filtered
                                    ? 'cashTransactions.noMatch'
                                    : 'cashTransactions.empty',
                            )}
                        </EmptyTitle>
                        <EmptyDescription>
                            {t(
                                filtered
                                    ? 'cashTransactions.noMatchDescription'
                                    : 'cashTransactions.emptyDescription',
                            )}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('cashTransactions.date')}
                                    </TableHead>
                                    <TableHead>
                                        {t('cashTransactions.account')}
                                    </TableHead>
                                    <TableHead>
                                        {t('cashTransactions.reference')}
                                    </TableHead>
                                    <TableHead>
                                        {t('cashTransactions.direction')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('cashTransactions.amount')}
                                    </TableHead>
                                    <TableHead>
                                        {t('cashTransactions.state')}
                                    </TableHead>
                                    {canClear && (
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                {t('cashTransactions.actions')}
                                            </span>
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transactions.data.transactions.map(
                                    (transaction) => {
                                        const name =
                                            accountName.get(
                                                transaction.cash_account_id,
                                            ) ?? '—';

                                        return (
                                            <TableRow key={transaction.id}>
                                                <TableCell className="tabular-nums">
                                                    {
                                                        transaction.transaction_date
                                                    }
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {name}
                                                </TableCell>
                                                <TableCell>
                                                    {transaction.reference ??
                                                        '—'}
                                                    {canReadJournals &&
                                                        transaction.journal_id !==
                                                            null && (
                                                            <Link
                                                                to="/w/$companyId/journals/$journalId"
                                                                params={{
                                                                    companyId,
                                                                    journalId:
                                                                        transaction.journal_id,
                                                                }}
                                                                className="ms-2 text-xs underline underline-offset-2"
                                                            >
                                                                {t(
                                                                    'cashTransactions.openJournal',
                                                                )}
                                                            </Link>
                                                        )}
                                                </TableCell>
                                                <TableCell>
                                                    {t(
                                                        transaction.direction ===
                                                            'in'
                                                            ? 'cashTransactions.directions.in'
                                                            : 'cashTransactions.directions.out',
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums">
                                                    {formatDecimal(
                                                        transaction.amount,
                                                        i18n.language,
                                                    )}{' '}
                                                    <span className="text-xs text-muted-foreground">
                                                        {transaction.currency}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {transaction.cleared ? (
                                                        <Badge variant="secondary">
                                                            {t(
                                                                'cashTransactions.clearedAt',
                                                                {
                                                                    date: transaction.cleared_on,
                                                                },
                                                            )}
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline">
                                                            {t(
                                                                'cashTransactions.notCleared',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                {canClear && (
                                                    <TableCell className="text-end">
                                                        <RowActions
                                                            label={`${name} ${transaction.transaction_date}`}
                                                        >
                                                            {transaction.cleared ? (
                                                                <DropdownMenuItem
                                                                    onClick={() =>
                                                                        unclear.mutate(
                                                                            transaction,
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'cashTransactions.unclearAction',
                                                                    )}
                                                                </DropdownMenuItem>
                                                            ) : (
                                                                <DropdownMenuItem
                                                                    onClick={() =>
                                                                        setClearing(
                                                                            transaction,
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'cashTransactions.clearAction',
                                                                    )}
                                                                </DropdownMenuItem>
                                                            )}
                                                        </RowActions>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        );
                                    },
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    <TablePagination
                        total={transactions.data.total}
                        page={transactions.data.page}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={(size) => {
                            setPageSize(size);
                            setPage(1);
                        }}
                    />
                </div>
            )}

            <ClearDialog
                key={clearing?.id ?? 'none'}
                companyId={companyId}
                transaction={clearing}
                onOpenChange={(open) => {
                    if (!open) {
                        setClearing(null);
                    }
                }}
            />
        </>
    );
}
