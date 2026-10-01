import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { BookTextIcon, XIcon } from 'lucide-react';
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
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
import { formatDecimal, isPositive, sumDecimals } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import {
    accountNamesQuery,
    journalsQuery,
    type Journal,
} from '@/modules/general-ledger/journals';

export type JournalSearch = {
    account_id?: string;
    from?: string;
    to?: string;
};

/** Debit and credit that a journal puts on one account, or its total when no account is chosen. */
function amountsOf(journal: Journal, accountId: string | undefined) {
    const lines = accountId
        ? journal.lines.filter((line) => line.account_id === accountId)
        : journal.lines;

    return {
        debit: sumDecimals(lines.map((line) => line.debit)),
        credit: sumDecimals(lines.map((line) => line.credit)),
    };
}

/**
 * Posted journals (GL-FR-05), newest first. Filtering by account is the drill-down of a report row:
 * the page then shows what each journal did to that account.
 */
export function JournalsPage({
    companyId,
    search,
    onSearchChange,
    canReadAccounts,
}: {
    companyId: string;
    search: JournalSearch;
    onSearchChange: (search: JournalSearch) => void;
    canReadAccounts: boolean;
}) {
    const { t, i18n } = useTranslation();
    const [page, setPage] = useState(1);

    useDocumentTitle(t('journals.list.pageTitle'));

    const list = useQuery(journalsQuery(companyId, { ...search, page }));
    const accounts = useQuery({
        ...accountNamesQuery(companyId),
        enabled: canReadAccounts && Boolean(search.account_id),
    });
    const account = search.account_id
        ? accounts.data?.[search.account_id]
        : undefined;
    const filtered = Boolean(search.account_id || search.from || search.to);
    const change = (next: JournalSearch) => {
        setPage(1);
        onSearchChange(next);
    };

    const money = (value: string) =>
        isPositive(value) ? formatDecimal(value, i18n.language) : '';

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('journals.list.title')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('journals.list.description')}
                </p>
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <Field className="w-44">
                    <FieldLabel htmlFor="journals-from">
                        {t('journals.list.from')}
                    </FieldLabel>
                    <Input
                        id="journals-from"
                        type="date"
                        value={search.from ?? ''}
                        onChange={(event) =>
                            change({
                                ...search,
                                from: event.target.value || undefined,
                            })
                        }
                    />
                </Field>
                <Field className="w-44">
                    <FieldLabel htmlFor="journals-to">
                        {t('journals.list.to')}
                    </FieldLabel>
                    <Input
                        id="journals-to"
                        type="date"
                        value={search.to ?? ''}
                        onChange={(event) =>
                            change({
                                ...search,
                                to: event.target.value || undefined,
                            })
                        }
                    />
                </Field>
                {search.account_id && (
                    <Badge
                        variant="secondary"
                        className="h-8 gap-2 px-3 text-sm"
                    >
                        {t('journals.list.filteredBy', {
                            account: account
                                ? `${account.code} · ${account.name}`
                                : search.account_id,
                        })}
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            aria-label={t('journals.list.clearAccount')}
                            onClick={() =>
                                change({ ...search, account_id: undefined })
                            }
                        >
                            <XIcon aria-hidden />
                        </Button>
                    </Badge>
                )}
            </div>

            {list.isError ? (
                <FormAlert>{genericErrorMessage(list.error, t)}</FormAlert>
            ) : list.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : list.data.total === 0 && !filtered ? (
                <Empty className="max-w-xl border">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <BookTextIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('journals.list.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('journals.list.emptyDescription')}
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
                                        {t('journals.list.number')}
                                    </TableHead>
                                    <TableHead>
                                        {t('journals.list.date')}
                                    </TableHead>
                                    <TableHead>
                                        {t('journals.list.memo')}
                                    </TableHead>
                                    <TableHead>
                                        {t('journals.list.source')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {search.account_id
                                            ? t('journals.list.accountDebit')
                                            : t('journals.list.amount')}
                                    </TableHead>
                                    {search.account_id && (
                                        <TableHead className="text-end">
                                            {t('journals.list.accountCredit')}
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.data.journals.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={search.account_id ? 6 : 5}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            {t('journals.list.noMatch')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {list.data.journals.map((journal) => {
                                    const amounts = amountsOf(
                                        journal,
                                        search.account_id,
                                    );

                                    return (
                                        <TableRow key={journal.id}>
                                            <TableCell className="font-medium">
                                                <Link
                                                    to="/w/$companyId/journals/$journalId"
                                                    params={{
                                                        companyId,
                                                        journalId: journal.id,
                                                    }}
                                                    aria-label={t(
                                                        'journals.list.open',
                                                        {
                                                            number: journal.number,
                                                        },
                                                    )}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {journal.number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                {journal.accounting_date}
                                            </TableCell>
                                            <TableCell className="max-w-xs truncate">
                                                {journal.description}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">
                                                    {t(
                                                        `journals.sources.${journal.source}`,
                                                        {
                                                            defaultValue:
                                                                journal.source,
                                                        },
                                                    )}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {search.account_id
                                                    ? money(amounts.debit)
                                                    : formatDecimal(
                                                          amounts.debit,
                                                          i18n.language,
                                                      )}
                                            </TableCell>
                                            {search.account_id && (
                                                <TableCell className="text-end tabular-nums">
                                                    {money(amounts.credit)}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                        <span aria-live="polite">
                            {t('journals.list.count', {
                                count: list.data.total,
                            })}
                        </span>
                        {list.data.lastPage > 1 && (
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={list.data.page <= 1}
                                    onClick={() => setPage(list.data.page - 1)}
                                >
                                    {t('journals.list.previous')}
                                </Button>
                                <span>
                                    {t('journals.list.pageOf', {
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
                                    {t('journals.list.next')}
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
