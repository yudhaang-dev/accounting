import { ExportButton } from '@/modules/reporting/components/export-button';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { BookOpenIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatIsoDate, todayIso } from '@/lib/date';
import { formatDecimal, isPositive } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { lineAccountsQuery } from '@/modules/transaction-entry/entries';
import { CoverageNotice } from '@/modules/reporting/components/coverage-notice';
import { ReportFilterBar } from '@/modules/reporting/components/report-filter-bar';
import {
    exportGeneralLedger,
    generalLedgerQuery,
} from '@/modules/reporting/general-ledger';
import {
    emptyFilter,
    type ReportFilter,
} from '@/modules/reporting/report-filter';

export type GeneralLedgerSearch = {
    account_id?: string;
    from?: string;
    to?: string;
};

/**
 * The general ledger of one account (RP-FR-06): every line of the range with a running balance, the
 * amount in the transaction currency when it is not the functional one, links to the journal and the
 * entry it came from, and a CSV export.
 */
export function GeneralLedgerPage({
    companyId,
    search,
    onAccountChange,
    canReadAccounts,
    canReadJournals,
    canReadEntries,
}: {
    companyId: string;
    search: GeneralLedgerSearch;
    onAccountChange: (accountId: string) => void;
    canReadAccounts: boolean;
    canReadJournals: boolean;
    canReadEntries: boolean;
}) {
    const { t, i18n } = useTranslation();
    const [filter, setFilter] = useState<ReportFilter>(() => ({
        ...emptyFilter(),
        from: search.from ?? `${new Date().getFullYear()}-01-01`,
        to: search.to ?? todayIso(),
    }));
    const accountId = search.account_id ?? '';

    useDocumentTitle(t('generalLedger.pageTitle'));

    const accounts = useQuery({
        ...lineAccountsQuery(companyId),
        enabled: canReadAccounts,
    });
    const report = useQuery({
        ...generalLedgerQuery(companyId, accountId, filter),
        enabled: accountId !== '',
    });

    const money = (value: string) =>
        isPositive(value) ? formatDecimal(value, i18n.language) : '';
    const amount = (value: string) => formatDecimal(value, i18n.language);

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('generalLedger.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('generalLedger.description')}
                    </p>
                </div>
                <ExportButton
                    disabled={!report.data}
                    formats={['csv', 'xlsx', 'pdf']}
                    onExport={(format) =>
                        exportGeneralLedger(
                            companyId,
                            accountId,
                            filter,
                            report.data?.account.code ?? 'account',
                            format,
                        )
                    }
                />
            </div>

            {canReadAccounts && (
                <Field className="w-80">
                    <FieldLabel htmlFor="gl-account">
                        {t('generalLedger.account')}
                    </FieldLabel>
                    <SearchableSelect
                        id="gl-account"
                        items={(accounts.data ?? []).map((account) => ({
                            value: account.id,
                            label: `${account.code} · ${account.name}`,
                        }))}
                        value={accountId}
                        onValueChange={onAccountChange}
                        placeholder={t('generalLedger.pickAccount')}
                        emptyText={t('common.noMatch')}
                    />
                </Field>
            )}

            <ReportFilterBar
                companyId={companyId}
                value={filter}
                onApply={setFilter}
            />

            {accountId === '' ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <BookOpenIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('generalLedger.noAccount')}</EmptyTitle>
                        <EmptyDescription>
                            {t('generalLedger.noAccountDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : report.isError ? (
                <FormAlert>
                    {isApiError(report.error) &&
                    report.error.status === 422 &&
                    report.error.message
                        ? report.error.message
                        : genericErrorMessage(report.error, t)}
                </FormAlert>
            ) : report.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <h2 className="text-base font-medium">
                            <span className="font-mono text-sm">
                                {report.data.account.code}
                            </span>{' '}
                            {report.data.account.name}
                        </h2>
                        <span className="text-sm text-muted-foreground">
                            {t('generalLedger.currency', {
                                currency: report.data.functional_currency,
                            })}
                        </span>
                    </div>

                    <CoverageNotice report={report.data} />

                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('generalLedger.date')}
                                    </TableHead>
                                    <TableHead>
                                        {t('generalLedger.journal')}
                                    </TableHead>
                                    <TableHead>
                                        {t('generalLedger.descriptionColumn')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('generalLedger.debit')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('generalLedger.credit')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('generalLedger.balance')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow>
                                    <TableCell colSpan={5} className="italic">
                                        {t('generalLedger.opening')}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {amount(report.data.opening_balance)}
                                    </TableCell>
                                </TableRow>
                                {report.data.lines.map((line, index) => (
                                    <TableRow
                                        key={`${line.journal_id}-${index}`}
                                    >
                                        <TableCell className="whitespace-nowrap">
                                            {formatIsoDate(
                                                line.accounting_date,
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            <div className="flex flex-col">
                                                {canReadJournals ? (
                                                    <Link
                                                        to="/w/$companyId/journals/$journalId"
                                                        params={{
                                                            companyId,
                                                            journalId:
                                                                line.journal_id,
                                                        }}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {line.journal_number}
                                                    </Link>
                                                ) : (
                                                    line.journal_number
                                                )}
                                                {line.entry_id &&
                                                    canReadEntries && (
                                                        <Link
                                                            to="/w/$companyId/entries/$entryId"
                                                            params={{
                                                                companyId,
                                                                entryId:
                                                                    line.entry_id,
                                                            }}
                                                            className="text-xs text-muted-foreground underline-offset-4 hover:underline"
                                                        >
                                                            {t(
                                                                'generalLedger.entry',
                                                            )}
                                                        </Link>
                                                    )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {line.description}
                                            {line.currency !==
                                                report.data
                                                    .functional_currency && (
                                                <div className="text-xs text-muted-foreground">
                                                    {`${line.currency} ${amount(line.transaction_amount)} × ${line.exchange_rate}`}
                                                </div>
                                            )}
                                            {line.source_references && (
                                                <div className="text-xs text-muted-foreground">
                                                    {Object.entries(
                                                        line.source_references,
                                                    )
                                                        .map(
                                                            ([key, value]) =>
                                                                `${key}: ${String(value)}`,
                                                        )
                                                        .join(' · ')}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {money(line.debit)}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {money(line.credit)}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {amount(line.balance)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell
                                        colSpan={3}
                                        className="font-medium"
                                    >
                                        {t('generalLedger.total')}
                                    </TableCell>
                                    <TableCell className="text-end font-medium tabular-nums">
                                        {amount(report.data.totals.debit)}
                                    </TableCell>
                                    <TableCell className="text-end font-medium tabular-nums">
                                        {amount(report.data.totals.credit)}
                                    </TableCell>
                                    <TableCell className="text-end font-medium tabular-nums">
                                        {amount(report.data.closing_balance)}
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </div>
            )}
        </>
    );
}
