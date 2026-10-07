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
import { todayIso } from '@/lib/date';
import { formatDecimal, isPositive } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
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

const COLUMNS = [
    'opening_debit',
    'opening_credit',
    'debit',
    'credit',
    'closing_debit',
    'closing_credit',
] as const;

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
                    {report.data.balanced ? (
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

                    {report.data.rows.length === 0 ? (
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
                                        <TableHead
                                            colSpan={2}
                                            className="text-center"
                                        >
                                            {t('trialBalance.opening')}
                                        </TableHead>
                                        <TableHead
                                            colSpan={2}
                                            className="text-center"
                                        >
                                            {t('trialBalance.movement')}
                                        </TableHead>
                                        <TableHead
                                            colSpan={2}
                                            className="text-center"
                                        >
                                            {t('trialBalance.closing')}
                                        </TableHead>
                                    </TableRow>
                                    <TableRow>
                                        {[0, 1, 2].flatMap((group) => [
                                            <TableHead
                                                key={`d${group}`}
                                                className="text-end"
                                            >
                                                {t('trialBalance.debit')}
                                            </TableHead>,
                                            <TableHead
                                                key={`c${group}`}
                                                className="text-end"
                                            >
                                                {t('trialBalance.credit')}
                                            </TableHead>,
                                        ])}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {report.data.rows.map((row) => (
                                        <TableRow key={row.account_id}>
                                            <TableCell className="font-mono text-xs">
                                                {row.code}
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
                                            {COLUMNS.map((column) => (
                                                <TableCell
                                                    key={column}
                                                    className="text-end tabular-nums"
                                                >
                                                    {money(row[column])}
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
                                        {COLUMNS.map((column) => (
                                            <TableCell
                                                key={column}
                                                className="text-end font-medium tabular-nums"
                                            >
                                                {formatDecimal(
                                                    report.data.totals[column],
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
