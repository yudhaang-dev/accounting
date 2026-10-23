import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ScaleIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
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
import { useDocumentTitle } from '@/hooks/use-document-title';
import { todayIso } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import { FilterSelect } from '@/modules/subledger/components/filter-select';
import {
    CausesDialog,
    WaiveDialog,
} from '@/modules/subledger/components/reconciliation-dialogs';
import {
    reconciliationsQuery,
    runReconciliation,
    type Reconciliation,
    type ReconciliationStatus,
} from '@/modules/subledger/reconciliations';

const STATUS_VARIANT = {
    balanced: 'secondary',
    difference: 'destructive',
    waived: 'outline',
} as const;

const statusOf = (item: Reconciliation): ReconciliationStatus =>
    item.status in STATUS_VARIANT
        ? (item.status as ReconciliationStatus)
        : 'difference';

/**
 * Reconciliation of control accounts and cash/bank accounts with their subledger as of a date
 * (SL-FR-09). A difference lists the journals that explain it and blocks the closing of the year
 * until it is resolved or waived with a reason.
 */
export function ReconciliationsPage({
    companyId,
    initialAsOf,
    canRun,
    canWaive,
    canReadJournals,
}: {
    companyId: string;
    /** Opens on the runs of this date, as linked from the year closing checklist. */
    initialAsOf?: string;
    canRun: boolean;
    canWaive: boolean;
    canReadJournals: boolean;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [asOf, setAsOf] = useState<string | undefined>(initialAsOf);
    const [status, setStatus] = useState<ReconciliationStatus | undefined>();
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('reconciliations');
    const [runDate, setRunDate] = useState(initialAsOf ?? todayIso());
    const [runError, setRunError] = useState<string | null>(null);
    const [viewing, setViewing] = useState<Reconciliation | null>(null);
    const [waiving, setWaiving] = useState<Reconciliation | null>(null);

    useDocumentTitle(t('reconciliations.pageTitle'));

    const runs = useQuery(
        reconciliationsQuery(companyId, {
            asOf,
            status,
            page,
            perPage: pageSize,
        }),
    );
    const run = useMutation({
        mutationFn: () => runReconciliation(companyId, runDate),
        onSuccess: async (result) => {
            await Promise.all(
                ['reconciliations', 'fiscal-years'].map((key) =>
                    queryClient.invalidateQueries({
                        queryKey: ['companies', companyId, key],
                    }),
                ),
            );
            setAsOf(runDate);
            setPage(1);
            toast.success(
                t('reconciliations.ran', {
                    count: result.length,
                    differences: result.filter(
                        (item) => item.status === 'difference',
                    ).length,
                }),
            );
        },
        onError: (failure) => setRunError(actionErrorMessage(failure, t)),
    });

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('reconciliations.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('reconciliations.description')}
                </p>
            </div>

            {canRun && (
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setRunError(null);
                        run.mutate();
                    }}
                >
                    <div className="flex flex-col gap-1.5">
                        <span
                            id="reconciliation-run-date"
                            className="text-sm font-medium"
                        >
                            {t('reconciliations.runDate')}
                        </span>
                        <DatePicker
                            labelledBy="reconciliation-run-date"
                            value={runDate}
                            onChange={setRunDate}
                        />
                    </div>
                    <Button type="submit" disabled={run.isPending}>
                        {run.isPending
                            ? t('reconciliations.running')
                            : t('reconciliations.run')}
                    </Button>
                </form>
            )}
            {runError && <FormAlert>{runError}</FormAlert>}

            <div className="flex flex-wrap items-center gap-3">
                <FilterSelect
                    label={t('reconciliations.statusLabel')}
                    anyLabel={t('reconciliations.allStatuses')}
                    options={(
                        ['difference', 'waived', 'balanced'] as const
                    ).map((value) => ({
                        value,
                        label: t(`reconciliations.status.${value}`),
                    }))}
                    value={status}
                    onChange={(value) => {
                        setStatus(value);
                        setPage(1);
                    }}
                />
                {asOf !== undefined && (
                    <div className="flex items-center gap-2 text-sm">
                        <Badge variant="secondary">
                            {t('reconciliations.forDate', { date: asOf })}
                        </Badge>
                        <button
                            type="button"
                            className="underline underline-offset-2"
                            onClick={() => {
                                setAsOf(undefined);
                                setPage(1);
                            }}
                        >
                            {t('reconciliations.showAll')}
                        </button>
                    </div>
                )}
            </div>

            {runs.isError ? (
                <FormAlert>{genericErrorMessage(runs.error, t)}</FormAlert>
            ) : runs.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : runs.data.total === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ScaleIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('reconciliations.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('reconciliations.emptyDescription')}
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
                                        {t('reconciliations.asOf')}
                                    </TableHead>
                                    <TableHead>
                                        {t('reconciliations.account')}
                                    </TableHead>
                                    <TableHead>
                                        {t('reconciliations.currency')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('reconciliations.ledger')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('reconciliations.detail')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('reconciliations.difference')}
                                    </TableHead>
                                    <TableHead>
                                        {t('reconciliations.statusLabel')}
                                    </TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">
                                            {t('reconciliations.actions')}
                                        </span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {runs.data.reconciliations.map((item) => {
                                    const itemStatus = statusOf(item);

                                    return (
                                        <TableRow key={item.id}>
                                            <TableCell className="tabular-nums">
                                                {item.as_of}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {item.account_code}
                                                <div className="text-xs font-normal text-muted-foreground">
                                                    {t(
                                                        item.kind === 'cash'
                                                            ? 'reconciliations.kindCash'
                                                            : 'reconciliations.kindControl',
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {item.currency}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {formatMoney(
                                                    item.ledger_balance,
                                                    i18n.language,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {formatMoney(
                                                    item.detail_total,
                                                    i18n.language,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {formatMoney(
                                                    item.difference,
                                                    i18n.language,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col gap-0.5">
                                                    <Badge
                                                        variant={
                                                            STATUS_VARIANT[
                                                                itemStatus
                                                            ]
                                                        }
                                                    >
                                                        {t(
                                                            `reconciliations.status.${itemStatus}`,
                                                        )}
                                                    </Badge>
                                                    {item.waiver_reason && (
                                                        <span className="max-w-56 text-xs">
                                                            {item.waiver_reason}
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-end">
                                                {itemStatus !== 'balanced' && (
                                                    <RowActions
                                                        label={
                                                            item.account_code
                                                        }
                                                    >
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                setViewing(item)
                                                            }
                                                        >
                                                            {t(
                                                                'reconciliations.viewCauses',
                                                            )}
                                                        </DropdownMenuItem>
                                                        {canWaive &&
                                                            itemStatus ===
                                                                'difference' && (
                                                                <DropdownMenuItem
                                                                    onClick={() =>
                                                                        setWaiving(
                                                                            item,
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'reconciliations.waiveAction',
                                                                    )}
                                                                </DropdownMenuItem>
                                                            )}
                                                    </RowActions>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>

                    <TablePagination
                        total={runs.data.total}
                        page={runs.data.page}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={(size) => {
                            setPageSize(size);
                            setPage(1);
                        }}
                    />
                </div>
            )}

            <CausesDialog
                companyId={companyId}
                reconciliation={viewing}
                canReadJournals={canReadJournals}
                onClose={() => setViewing(null)}
            />
            <WaiveDialog
                companyId={companyId}
                reconciliation={waiving}
                onClose={() => setWaiving(null)}
            />
        </>
    );
}
