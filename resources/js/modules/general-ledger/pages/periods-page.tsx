import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CalendarRangeIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
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
import {
    createFiscalYears,
    fiscalYearsQuery,
    type FiscalYear,
} from '@/modules/general-ledger/calendar';
import { ReopenPeriodDialog } from '@/modules/general-ledger/components/reopen-period-dialog';
import {
    closePeriod,
    periodsQuery,
    type Period,
} from '@/modules/general-ledger/periods';

const STATUS_VARIANT = {
    open: 'default',
    soft_closed: 'secondary',
    closed: 'outline',
} as const;

const failure = (error: unknown, fallback: string): string =>
    isApiError(error) && error.status !== 0 ? error.message : fallback;

/** The year to open first: the one containing today, otherwise the most recent. */
function defaultYear(years: FiscalYear[]): FiscalYear | undefined {
    const today = new Date().toISOString().slice(0, 10);

    return (
        years.find((y) => y.start_date <= today && today <= y.end_date) ??
        years.at(-1)
    );
}

/** Periods per fiscal year (GL-FR-03, GL-FR-04): close, reopen with a reason, add the next year. */
export function PeriodsPage({
    companyId,
    canManage,
    canClose,
    canReopen,
}: {
    companyId: string;
    canManage: boolean;
    canClose: boolean;
    canReopen: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [chosenYear, setChosenYear] = useState<string | null>(null);
    const [reopening, setReopening] = useState<Period | null>(null);
    const [closing, setClosing] = useState<Period | null>(null);
    const asking = useLastDefined(closing);

    useDocumentTitle(t('periods.pageTitle'));

    const years = useQuery(fiscalYearsQuery(companyId));
    const yearId =
        chosenYear ?? (years.data ? defaultYear(years.data)?.id : undefined);
    const periods = useQuery({
        ...periodsQuery(companyId, yearId ?? ''),
        enabled: Boolean(yearId),
    });

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId],
        });

    const close = useMutation({
        mutationFn: (period: Period) => closePeriod(companyId, period.id),
        onSuccess: async (period) => {
            await refresh();
            toast.success(t('periods.closed', { name: period.name }));
        },
        onSettled: () => setClosing(null),
        onError: (error) =>
            toast.error(failure(error, genericErrorMessage(error, t))),
    });

    const addYear = useMutation({
        mutationFn: () => createFiscalYears(companyId, { count: 1 }),
        onSuccess: async (created) => {
            await refresh();
            setChosenYear(created.at(-1)?.id ?? null);
            toast.success(
                t('periods.yearAdded', { name: created.at(-1)?.name ?? '' }),
            );
        },
        onError: (error) =>
            toast.error(failure(error, genericErrorMessage(error, t))),
    });

    const yearItems = (years.data ?? []).map((year) => ({
        value: year.id,
        label: year.name,
    }));

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('periods.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('periods.description')}
                    </p>
                </div>
                {canManage && years.data && years.data.length > 0 && (
                    <Button
                        variant="outline"
                        disabled={addYear.isPending}
                        onClick={() => addYear.mutate()}
                    >
                        <PlusIcon data-icon="inline-start" />
                        {t('periods.addYear')}
                    </Button>
                )}
            </div>

            {years.isError ? (
                <FormAlert>{genericErrorMessage(years.error, t)}</FormAlert>
            ) : years.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : years.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <CalendarRangeIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('periods.noYears')}</EmptyTitle>
                        <EmptyDescription>
                            {t('periods.noYearsDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && (
                        <EmptyContent>
                            <Button
                                disabled={addYear.isPending}
                                onClick={() => addYear.mutate()}
                            >
                                <PlusIcon data-icon="inline-start" />
                                {t('periods.addYear')}
                            </Button>
                        </EmptyContent>
                    )}
                </Empty>
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="flex items-center gap-3">
                        <span
                            id="fiscal-year-label"
                            className="text-sm text-muted-foreground"
                        >
                            {t('periods.fiscalYear')}
                        </span>
                        <Select
                            value={yearId ?? ''}
                            items={yearItems}
                            onValueChange={(value) =>
                                setChosenYear(String(value))
                            }
                        >
                            <SelectTrigger
                                className="w-56"
                                aria-labelledby="fiscal-year-label"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    {yearItems.map((item) => (
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

                    {periods.isError ? (
                        <FormAlert>
                            {genericErrorMessage(periods.error, t)}
                        </FormAlert>
                    ) : periods.data === undefined ? (
                        <Skeleton className="h-64 w-full" />
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-14">
                                            {t('periods.number')}
                                        </TableHead>
                                        <TableHead>
                                            {t('periods.name')}
                                        </TableHead>
                                        <TableHead>
                                            {t('periods.start')}
                                        </TableHead>
                                        <TableHead>
                                            {t('periods.end')}
                                        </TableHead>
                                        <TableHead>
                                            {t('periods.status')}
                                        </TableHead>
                                        {(canClose || canReopen) && (
                                            <TableHead className="text-end">
                                                {t('periods.actions')}
                                            </TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {periods.data.map((period) => (
                                        <TableRow key={period.id}>
                                            <TableCell>
                                                {period.number}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {period.name}
                                            </TableCell>
                                            <TableCell>
                                                {period.start_date}
                                            </TableCell>
                                            <TableCell>
                                                {period.end_date}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        STATUS_VARIANT[
                                                            period.status as keyof typeof STATUS_VARIANT
                                                        ] ?? 'outline'
                                                    }
                                                >
                                                    {t(
                                                        `periods.statuses.${period.status}`,
                                                        {
                                                            defaultValue:
                                                                period.status,
                                                        },
                                                    )}
                                                </Badge>
                                            </TableCell>
                                            {(canClose || canReopen) && (
                                                <TableCell className="text-end">
                                                    {period.status === 'open' &&
                                                        canClose && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled={
                                                                    close.isPending
                                                                }
                                                                aria-label={t(
                                                                    'periods.close',
                                                                    {
                                                                        name: period.name,
                                                                    },
                                                                )}
                                                                onClick={() =>
                                                                    setClosing(
                                                                        period,
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'periods.closeAction',
                                                                )}
                                                            </Button>
                                                        )}
                                                    {period.status ===
                                                        'closed' &&
                                                        canReopen && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                aria-label={t(
                                                                    'periods.reopen',
                                                                    {
                                                                        name: period.name,
                                                                    },
                                                                )}
                                                                onClick={() =>
                                                                    setReopening(
                                                                        period,
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'periods.reopenAction',
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
                    )}
                </div>
            )}

            <ConfirmDialog
                open={closing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setClosing(null);
                    }
                }}
                title={t('confirm.closePeriod.title', {
                    name: asking?.name ?? '',
                })}
                description={t('confirm.closePeriod.description')}
                confirmLabel={t('confirm.closePeriod.action')}
                pending={close.isPending}
                onConfirm={() => closing && close.mutate(closing)}
            />
            <ReopenPeriodDialog
                companyId={companyId}
                period={reopening}
                onClose={() => setReopening(null)}
            />
        </>
    );
}
