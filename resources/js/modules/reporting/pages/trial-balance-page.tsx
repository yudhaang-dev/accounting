import { useMutation, useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { CheckIcon, DownloadIcon, FileSpreadsheetIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Spinner } from '@/components/ui/spinner';
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
import { CoverageNotice } from '@/modules/reporting/components/coverage-notice';
import { ReportFilterBar } from '@/modules/reporting/components/report-filter-bar';
import {
    emptyFilter,
    type ReportFilter,
} from '@/modules/reporting/report-filter';
import {
    exportTrialBalance,
    trialBalanceQuery,
} from '@/modules/reporting/trial-balance';

const defaultFilter = (): ReportFilter => ({
    ...emptyFilter(),
    from: `${new Date().getFullYear()}-01-01`,
    to: todayIso(),
});

type Group = { label: string; keys: [string, string] };

const STANDARD: Group[] = [
    {
        label: 'trialBalance.opening',
        keys: ['opening_debit', 'opening_credit'],
    },
    { label: 'trialBalance.movement', keys: ['debit', 'credit'] },
    {
        label: 'trialBalance.closing',
        keys: ['closing_debit', 'closing_credit'],
    },
];

// The workpaper shows the balance before the adjustments, the adjustments, and the balance after (RP-FR-05).
const WORKPAPER: Group[] = [
    { label: 'trialBalance.before', keys: ['before_debit', 'before_credit'] },
    {
        label: 'trialBalance.adjustments',
        keys: ['adjustment_debit', 'adjustment_credit'],
    },
    { label: 'trialBalance.after', keys: ['after_debit', 'after_credit'] },
];

/**
 * Trial balance of a date range (RP-FR-05, RP-AC-01): opening, movement and closing per account,
 * totals, a visible balanced status, drill-down to the journals of an account, and CSV export.
 */
export function TrialBalancePage({ companyId }: { companyId: string }) {
    const { t, i18n } = useTranslation();
    const [filter, setFilter] = useState<ReportFilter>(defaultFilter);

    useDocumentTitle(t('trialBalance.pageTitle'));

    const report = useQuery(trialBalanceQuery(companyId, filter));

    const download = useMutation({
        mutationFn: () => exportTrialBalance(companyId, filter),
        onError: (error) =>
            toast.error(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : t('trialBalance.exportFailed'),
            ),
    });

    const workpaper = report.data?.workpaper;
    const groups = workpaper ? WORKPAPER : STANDARD;
    const columns = groups.flatMap((group) => group.keys);
    const rows: Record<string, string>[] =
        workpaper?.rows ?? report.data?.rows ?? [];
    const totals: Record<string, string> =
        workpaper?.totals ?? report.data?.totals ?? {};
    const balanced = workpaper ? workpaper.balanced : report.data?.balanced;

    const money = (value: string) =>
        isPositive(value) ? formatDecimal(value, i18n.language) : '';

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('trialBalance.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('trialBalance.description')}
                    </p>
                </div>
                <Button
                    variant="outline"
                    disabled={download.isPending || !report.data}
                    aria-busy={download.isPending}
                    onClick={() => download.mutate()}
                >
                    {download.isPending ? (
                        <Spinner data-icon="inline-start" aria-hidden />
                    ) : (
                        <DownloadIcon data-icon="inline-start" />
                    )}
                    {download.isPending
                        ? t('trialBalance.exporting')
                        : t('trialBalance.export')}
                </Button>
            </div>

            <ReportFilterBar
                companyId={companyId}
                value={filter}
                onApply={setFilter}
                withWorkpaper
            />

            {report.isError ? (
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
                    {balanced ? (
                        <div className="flex items-center gap-2 text-sm">
                            <Badge variant="secondary">
                                <CheckIcon data-icon="inline-start" />
                                {t('trialBalance.balanced')}
                            </Badge>
                            <span className="text-muted-foreground">
                                {t('trialBalance.currency', {
                                    currency: report.data.functional_currency,
                                })}
                            </span>
                        </div>
                    ) : (
                        <Alert variant="destructive" role="alert">
                            <AlertTitle>
                                {t('trialBalance.unbalancedTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {t('trialBalance.unbalancedDescription')}
                            </AlertDescription>
                        </Alert>
                    )}

                    {report.data.conversion_batch && (
                        <Alert role="status">
                            <AlertTitle>
                                {t('trialBalance.conversionTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {t('trialBalance.conversion', {
                                    date: formatIsoDate(
                                        report.data.conversion_batch
                                            .cutover_date,
                                        i18n.language,
                                    ),
                                })}{' '}
                                <Link
                                    to="/w/$companyId/journals/$journalId"
                                    params={{
                                        companyId,
                                        journalId:
                                            report.data.conversion_batch
                                                .journal_id,
                                    }}
                                    className="underline underline-offset-4"
                                >
                                    {
                                        report.data.conversion_batch
                                            .journal_number
                                    }
                                </Link>
                            </AlertDescription>
                        </Alert>
                    )}

                    <CoverageNotice report={report.data} />

                    {rows.length === 0 ? (
                        <Empty className="max-w-xl flex-none border py-10">
                            <EmptyHeader>
                                <EmptyMedia variant="icon">
                                    <FileSpreadsheetIcon />
                                </EmptyMedia>
                                <EmptyTitle>
                                    {t('trialBalance.empty')}
                                </EmptyTitle>
                                <EmptyDescription>
                                    {t('trialBalance.emptyDescription')}
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead rowSpan={2} className="w-24">
                                            {t('trialBalance.code')}
                                        </TableHead>
                                        <TableHead rowSpan={2}>
                                            {t('trialBalance.account')}
                                        </TableHead>
                                        {groups.map((group) => (
                                            <TableHead
                                                key={group.label}
                                                colSpan={2}
                                                className="text-center"
                                            >
                                                {t(
                                                    group.label as 'trialBalance.opening',
                                                )}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                    <TableRow>
                                        {groups.flatMap((group) => [
                                            <TableHead
                                                key={`d${group.label}`}
                                                className="text-end"
                                            >
                                                {t('trialBalance.debit')}
                                            </TableHead>,
                                            <TableHead
                                                key={`c${group.label}`}
                                                className="text-end"
                                            >
                                                {t('trialBalance.credit')}
                                            </TableHead>,
                                        ])}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.account_id}>
                                            <TableCell className="font-mono text-xs">
                                                <Link
                                                    to="/w/$companyId/reports/general-ledger"
                                                    params={{ companyId }}
                                                    search={{
                                                        account_id:
                                                            row.account_id,
                                                        from: filter.from,
                                                        to: filter.to,
                                                    }}
                                                    aria-label={t(
                                                        'trialBalance.ledger',
                                                        { code: row.code },
                                                    )}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {row.code}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    to="/w/$companyId/journals"
                                                    params={{ companyId }}
                                                    search={{
                                                        account_id:
                                                            row.account_id,
                                                        from: filter.from,
                                                        to: filter.to,
                                                    }}
                                                    aria-label={t(
                                                        'trialBalance.drill',
                                                        { code: row.code },
                                                    )}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {row.name}
                                                </Link>
                                            </TableCell>
                                            {columns.map((column) => (
                                                <TableCell
                                                    key={column}
                                                    className="text-end tabular-nums"
                                                >
                                                    {money(row[column] ?? '')}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell
                                            colSpan={2}
                                            className="font-medium"
                                        >
                                            {t('trialBalance.total')}
                                        </TableCell>
                                        {columns.map((column) => (
                                            <TableCell
                                                key={column}
                                                className="text-end font-medium tabular-nums"
                                            >
                                                {formatDecimal(
                                                    totals[column] ?? '',
                                                    i18n.language,
                                                )}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    )}
                </div>
            )}
        </>
    );
}
