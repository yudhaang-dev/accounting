import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { formatMoney } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import {
    bankInvalidationKeys,
    bankLinesQuery,
    bankReportQuery,
    bankStatementQuery,
    unmatchBankLine,
    type BankReport,
    type BankStatementLine,
} from '@/modules/subledger/bank-statements';
import { cashAccountsQuery } from '@/modules/subledger/cash';
import { BankMatchDialog } from '@/modules/subledger/components/bank-match-dialog';

const ANY = '__any';

function Figure({
    label,
    value,
    currency,
    strong,
}: {
    label: string;
    value: string;
    currency: string;
    strong?: boolean;
}) {
    const { i18n } = useTranslation();

    return (
        <div
            className={
                strong
                    ? 'flex justify-between gap-6 border-t pt-2 font-semibold'
                    : 'flex justify-between gap-6'
            }
        >
            <dt>{label}</dt>
            <dd className="tabular-nums">
                {formatMoney(value, i18n.language)}{' '}
                <span className="text-xs font-normal text-muted-foreground">
                    {currency}
                </span>
            </dd>
        </div>
    );
}

function Report({ report }: { report: BankReport }) {
    const { t, i18n } = useTranslation();
    const money = (value: string) => formatMoney(value, i18n.language);

    return (
        <section
            className="flex flex-col gap-4"
            aria-label={t('bankStatements.report.title')}
        >
            <div className="flex flex-wrap items-center gap-3">
                <h2 className="text-lg font-semibold tracking-tight">
                    {t('bankStatements.report.title')}
                </h2>
                {report.reconciled ? (
                    <Badge variant="secondary">
                        {t('bankStatements.report.reconciled')}
                    </Badge>
                ) : (
                    <Badge variant="destructive">
                        {t('bankStatements.report.difference', {
                            amount: money(report.difference),
                            currency: report.currency,
                        })}
                    </Badge>
                )}
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <dl className="flex flex-col gap-2 rounded-lg border p-4 text-sm">
                    <Figure
                        label={t('bankStatements.report.statementBalance', {
                            date: report.closing_date,
                        })}
                        value={report.statement_balance}
                        currency={report.currency}
                    />
                    <Figure
                        label={t('bankStatements.report.outstandingIn')}
                        value={report.outstanding_in}
                        currency={report.currency}
                    />
                    <Figure
                        label={t('bankStatements.report.outstandingOut')}
                        value={report.outstanding_out}
                        currency={report.currency}
                    />
                    <Figure
                        strong
                        label={t('bankStatements.report.adjustedStatement')}
                        value={report.adjusted_statement_balance}
                        currency={report.currency}
                    />
                </dl>
                <dl className="flex flex-col gap-2 rounded-lg border p-4 text-sm">
                    <Figure
                        label={t('bankStatements.report.bookBalance', {
                            date: report.closing_date,
                        })}
                        value={report.book_balance}
                        currency={report.currency}
                    />
                    <Figure
                        label={t('bankStatements.report.unrecordedIn')}
                        value={report.unrecorded_in}
                        currency={report.currency}
                    />
                    <Figure
                        label={t('bankStatements.report.unrecordedOut')}
                        value={report.unrecorded_out}
                        currency={report.currency}
                    />
                    <Figure
                        strong
                        label={t('bankStatements.report.adjustedBook')}
                        value={report.adjusted_book_balance}
                        currency={report.currency}
                    />
                </dl>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="flex flex-col gap-2">
                    <h3 className="text-sm font-medium">
                        {t('bankStatements.report.outstandingTitle')}
                    </h3>
                    {report.outstanding_transactions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('bankStatements.report.noneOutstanding')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <Table
                                aria-label={t(
                                    'bankStatements.report.outstandingTitle',
                                )}
                            >
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('cashTransactions.date')}
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
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {report.outstanding_transactions.map(
                                        (row) => (
                                            <TableRow
                                                key={row.cash_transaction_id}
                                            >
                                                <TableCell className="tabular-nums">
                                                    {row.transaction_date}
                                                </TableCell>
                                                <TableCell>
                                                    {row.reference ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {t(
                                                        `cashTransactions.directions.${row.direction === 'in' ? 'in' : 'out'}`,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums">
                                                    {money(row.amount)}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-2">
                    <h3 className="text-sm font-medium">
                        {t('bankStatements.report.unrecordedTitle')}
                    </h3>
                    {report.unrecorded_lines.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('bankStatements.report.noneUnrecorded')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <Table
                                aria-label={t(
                                    'bankStatements.report.unrecordedTitle',
                                )}
                            >
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('cashTransactions.date')}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'bankStatements.lines.description',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t('cashTransactions.direction')}
                                        </TableHead>
                                        <TableHead className="text-end">
                                            {t('cashTransactions.amount')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {report.unrecorded_lines.map((row) => (
                                        <TableRow key={row.line_id}>
                                            <TableCell className="tabular-nums">
                                                {row.transaction_date}
                                            </TableCell>
                                            <TableCell>
                                                {row.description ??
                                                    row.reference ??
                                                    '—'}
                                            </TableCell>
                                            <TableCell>
                                                {t(
                                                    `cashTransactions.directions.${row.direction === 'in' ? 'in' : 'out'}`,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {money(row.amount)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}

/**
 * One imported statement (SL-FR-13): the reconciliation of its closing balance with the books, and its lines,
 * which a user with the permission matches to cash transactions or unmatches.
 */
export function BankStatementPage({
    companyId,
    statementId,
    canMatch,
}: {
    companyId: string;
    statementId: string;
    /** Whether the user may match lines: it needs the cash transactions to choose from. */
    canMatch: boolean;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [matched, setMatched] = useState<boolean | undefined>();
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('bank-statement-lines');
    const [matching, setMatching] = useState<BankStatementLine | null>(null);

    useDocumentTitle(t('bankStatements.statementTitle'));

    const statement = useQuery(bankStatementQuery(companyId, statementId));
    const cashAccounts = useQuery(cashAccountsQuery(companyId));
    const report = useQuery(bankReportQuery(companyId, statementId));
    const lines = useQuery(
        bankLinesQuery(companyId, statementId, {
            matched,
            page,
            perPage: pageSize,
        }),
    );
    const account = cashAccounts.data?.find(
        (row) => row.id === statement.data?.cash_account_id,
    );
    const stateItems = [
        { value: ANY, label: t('bankStatements.lines.allStates') },
        { value: 'open', label: t('bankStatements.lines.open') },
        { value: 'matched', label: t('bankStatements.lines.matched') },
    ];

    const unmatch = useMutation({
        mutationFn: (line: BankStatementLine) =>
            unmatchBankLine(companyId, line.id),
        onSuccess: async () => {
            await Promise.all(
                bankInvalidationKeys(companyId).map((queryKey) =>
                    queryClient.invalidateQueries({ queryKey }),
                ),
            );
            toast.success(t('bankStatements.lines.unmatched'));
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
    });

    if (statement.isError) {
        return <FormAlert>{genericErrorMessage(statement.error, t)}</FormAlert>;
    }

    if (statement.data === undefined) {
        return <Skeleton className="h-48 w-full" />;
    }

    const current = statement.data;

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {account?.name ?? t('bankStatements.statementTitle')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('bankStatements.summary', {
                        file: current.file_name ?? '—',
                        from: current.period_from,
                        to: current.period_to,
                        matched: current.matched_count,
                        lines: current.line_count,
                    })}
                </p>
                <Link
                    to="/w/$companyId/bank-statements"
                    params={{ companyId }}
                    className="w-fit text-sm underline underline-offset-2"
                >
                    {t('bankStatements.backToList')}
                </Link>
            </div>

            {report.isError ? (
                <FormAlert>{genericErrorMessage(report.error, t)}</FormAlert>
            ) : report.data === undefined ? (
                <Skeleton className="h-40 w-full" />
            ) : (
                <Report report={report.data} />
            )}

            <section
                className="flex flex-col gap-3"
                aria-label={t('bankStatements.lines.title')}
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold tracking-tight">
                        {t('bankStatements.lines.title')}
                    </h2>
                    <Select
                        value={
                            matched === undefined
                                ? ANY
                                : matched
                                  ? 'matched'
                                  : 'open'
                        }
                        items={stateItems}
                        onValueChange={(value) => {
                            setMatched(
                                value === 'matched'
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
                            aria-label={t('bankStatements.lines.state')}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {stateItems.map((item) => (
                                    <SelectItem
                                        key={item.value}
                                        value={item.value}
                                    >
                                        {item.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>

                {lines.isError ? (
                    <FormAlert>{genericErrorMessage(lines.error, t)}</FormAlert>
                ) : lines.data === undefined ? (
                    <Skeleton className="h-48 w-full" />
                ) : lines.data.total === 0 ? (
                    <p className="rounded-lg border px-4 py-8 text-center text-sm text-muted-foreground">
                        {t('bankStatements.lines.noMatch')}
                    </p>
                ) : (
                    <div className="flex flex-col gap-3">
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-16">
                                            {t('bankStatements.lines.number')}
                                        </TableHead>
                                        <TableHead>
                                            {t('cashTransactions.date')}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'bankStatements.lines.description',
                                            )}
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
                                            {t('bankStatements.lines.state')}
                                        </TableHead>
                                        {canMatch && (
                                            <TableHead className="w-28">
                                                <span className="sr-only">
                                                    {t(
                                                        'cashTransactions.actions',
                                                    )}
                                                </span>
                                            </TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {lines.data.lines.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell className="tabular-nums">
                                                {line.line_number}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {line.transaction_date}
                                            </TableCell>
                                            <TableCell>
                                                {line.description ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {line.reference ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {t(
                                                    `cashTransactions.directions.${line.direction === 'in' ? 'in' : 'out'}`,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {formatMoney(
                                                    line.amount,
                                                    i18n.language,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {line.cash_transaction_id ? (
                                                    <Badge variant="secondary">
                                                        {t(
                                                            'bankStatements.lines.matched',
                                                        )}
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline">
                                                        {t(
                                                            'bankStatements.lines.open',
                                                        )}
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            {canMatch && (
                                                <TableCell className="text-end">
                                                    {line.cash_transaction_id ? (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            disabled={
                                                                unmatch.isPending
                                                            }
                                                            aria-label={t(
                                                                'bankStatements.lines.unmatchLine',
                                                                {
                                                                    number: line.line_number,
                                                                },
                                                            )}
                                                            onClick={() =>
                                                                unmatch.mutate(
                                                                    line,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'bankStatements.lines.unmatch',
                                                            )}
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            aria-label={t(
                                                                'bankStatements.lines.matchLine',
                                                                {
                                                                    number: line.line_number,
                                                                },
                                                            )}
                                                            onClick={() =>
                                                                setMatching(
                                                                    line,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'bankStatements.lines.match',
                                                            )}
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <TablePagination
                            total={lines.data.total}
                            page={lines.data.page}
                            pageSize={pageSize}
                            onPageChange={setPage}
                            onPageSizeChange={(size) => {
                                setPageSize(size);
                                setPage(1);
                            }}
                        />
                    </div>
                )}
            </section>

            <BankMatchDialog
                companyId={companyId}
                statementId={statementId}
                line={matching}
                onOpenChange={(open) => {
                    if (!open) {
                        setMatching(null);
                    }
                }}
            />
        </>
    );
}
