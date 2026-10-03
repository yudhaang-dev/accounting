import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { PlusIcon, RepeatIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useLastDefined } from '@/hooks/use-last-defined';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import {
    SCHEDULE_STATUSES,
    schedulesQuery,
    skipSchedule,
    updateSchedule,
    type Schedule,
    type ScheduleStatus,
} from '@/modules/transaction-entry/recurring-schedules';
import { useTypeLabel } from '@/modules/transaction-entry/type-labels';

type Filter = ScheduleStatus | 'all';

/** "Every month", "Every 2 weeks": the rhythm of a schedule in words. */
function Rhythm({ schedule }: { schedule: Schedule }) {
    const { t } = useTranslation();
    const frequency = schedule.frequency as
        | 'daily'
        | 'weekly'
        | 'monthly'
        | 'quarterly'
        | 'yearly';

    return schedule.interval_count === 1
        ? t(`schedules.every.${frequency}`)
        : t(`schedules.everyN.${frequency}`, {
              count: schedule.interval_count,
          });
}

/**
 * Recurring journals (TE-FR-14): the schedules that make an entry on a rhythm, when each runs next, and
 * the ones that are stuck. A schedule that cannot make its entry stops at that date with the reason shown
 * here; it never skips on its own, and skipping is a decision someone takes.
 */
export function RecurringSchedulesPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const typeLabel = useTypeLabel(companyId);
    const [filter, setFilter] = useState<Filter>('all');
    const [skipping, setSkipping] = useState<Schedule | null>(null);
    const asking = useLastDefined(skipping);
    const [error, setError] = useState<string | null>(null);

    useDocumentTitle(t('schedules.pageTitle'));

    const list = useQuery(schedulesQuery(companyId));
    const stuck = (list.data ?? []).filter((schedule) => schedule.last_error);
    const shown = (list.data ?? []).filter(
        (schedule) => filter === 'all' || schedule.status === filter,
    );

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'recurring-schedules'],
        });

    const toggle = useMutation({
        mutationFn: (schedule: Schedule) =>
            updateSchedule(companyId, schedule.id, {
                lock_version: schedule.lock_version,
                name: schedule.name,
                transaction_type_version: schedule.transaction_type_version,
                end_date: schedule.end_date,
                initial_status: schedule.initial_status as
                    | 'draft'
                    | 'submitted',
                status: schedule.status === 'active' ? 'paused' : 'active',
                template: schedule.template as never,
            }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(
                    saved.status === 'active'
                        ? 'schedules.resumed'
                        : 'schedules.paused',
                    { name: saved.name },
                ),
            );
        },
        onError: (failure) => setError(actionErrorMessage(failure, t)),
    });

    const skip = useMutation({
        mutationFn: (schedule: Schedule) =>
            skipSchedule(companyId, schedule.id),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(t('schedules.skipped', { name: saved.name }));
        },
        onError: (failure) => setError(actionErrorMessage(failure, t)),
        onSettled: () => setSkipping(null),
    });

    const create = canManage && (
        <Button
            render={
                <Link
                    to="/w/$companyId/recurring-schedules/new"
                    params={{ companyId }}
                />
            }
        >
            <PlusIcon data-icon="inline-start" />
            {t('schedules.new')}
        </Button>
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('schedules.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('schedules.description')}
                    </p>
                </div>
                {create}
            </div>

            {stuck.length > 0 && (
                <Alert variant="destructive" role="alert">
                    <AlertDescription>
                        {t('schedules.stuck', { count: stuck.length })}
                    </AlertDescription>
                </Alert>
            )}
            {error && <FormAlert>{error}</FormAlert>}

            <ToggleGroup
                variant="outline"
                aria-label={t('schedules.filter')}
                value={[filter]}
                onValueChange={(next) => {
                    const picked = next[0];

                    if (
                        picked === 'all' ||
                        SCHEDULE_STATUSES.includes(picked as ScheduleStatus)
                    ) {
                        setFilter(picked as Filter);
                    }
                }}
            >
                <ToggleGroupItem value="all">
                    {t('schedules.all')}
                </ToggleGroupItem>
                {SCHEDULE_STATUSES.map((status) => (
                    <ToggleGroupItem key={status} value={status}>
                        {t(`schedules.statuses.${status}`)}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>

            {list.isError ? (
                <FormAlert>{genericErrorMessage(list.error, t)}</FormAlert>
            ) : list.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : list.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <RepeatIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('schedules.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('schedules.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && <EmptyContent>{create}</EmptyContent>}
                </Empty>
            ) : shown.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('schedules.noMatch')}
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('schedules.name')}</TableHead>
                                <TableHead>{t('schedules.rhythm')}</TableHead>
                                <TableHead>{t('schedules.nextRun')}</TableHead>
                                <TableHead>{t('schedules.lastRun')}</TableHead>
                                <TableHead>{t('schedules.status')}</TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">
                                        {t('schedules.actions')}
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.map((schedule) => (
                                <TableRow key={schedule.id}>
                                    <TableCell>
                                        <div className="flex flex-col gap-0.5">
                                            <span className="font-medium">
                                                {schedule.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {typeLabel(
                                                    schedule.transaction_type_key,
                                                )}
                                            </span>
                                            {schedule.last_error && (
                                                <span
                                                    className="max-w-sm text-xs text-destructive"
                                                    role="status"
                                                >
                                                    {t('schedules.failedAt', {
                                                        date:
                                                            schedule.next_run_on ??
                                                            '',
                                                        error: schedule.last_error,
                                                    })}
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Rhythm schedule={schedule} />
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {schedule.next_run_on ?? '–'}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {schedule.last_entry_id ? (
                                            <Link
                                                to="/w/$companyId/entries/$entryId"
                                                params={{
                                                    companyId,
                                                    entryId:
                                                        schedule.last_entry_id,
                                                }}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {schedule.last_run_on}
                                            </Link>
                                        ) : (
                                            (schedule.last_run_on ?? '–')
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                schedule.last_error
                                                    ? 'destructive'
                                                    : schedule.status ===
                                                        'active'
                                                      ? 'secondary'
                                                      : 'outline'
                                            }
                                        >
                                            {schedule.last_error
                                                ? t('schedules.statuses.stuck')
                                                : t(
                                                      `schedules.statuses.${schedule.status as ScheduleStatus}`,
                                                  )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-end">
                                        {canManage &&
                                            schedule.status !== 'completed' && (
                                                <RowActions
                                                    label={schedule.name}
                                                >
                                                    <DropdownMenuItem
                                                        render={
                                                            <Link
                                                                to="/w/$companyId/recurring-schedules/$scheduleId/edit"
                                                                params={{
                                                                    companyId,
                                                                    scheduleId:
                                                                        schedule.id,
                                                                }}
                                                            />
                                                        }
                                                    >
                                                        {t('schedules.edit')}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onClick={() => {
                                                            setError(null);
                                                            toggle.mutate(
                                                                schedule,
                                                            );
                                                        }}
                                                    >
                                                        {schedule.status ===
                                                        'active'
                                                            ? t(
                                                                  'schedules.pause',
                                                              )
                                                            : t(
                                                                  'schedules.resume',
                                                              )}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() => {
                                                            setError(null);
                                                            setSkipping(
                                                                schedule,
                                                            );
                                                        }}
                                                    >
                                                        {t('schedules.skip')}
                                                    </DropdownMenuItem>
                                                </RowActions>
                                            )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <ConfirmDialog
                open={skipping !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSkipping(null);
                    }
                }}
                title={t('schedules.skipTitle', {
                    date: asking?.next_run_on ?? '',
                })}
                description={t('schedules.skipDescription', {
                    name: asking?.name ?? '',
                })}
                confirmLabel={t('schedules.skipAction')}
                destructive
                pending={skip.isPending}
                onConfirm={() => skipping && skip.mutate(skipping)}
            />
        </>
    );
}
