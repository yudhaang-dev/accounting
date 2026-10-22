import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { PlayIcon, SigmaIcon } from 'lucide-react';
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
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatIsoDate } from '@/lib/date';
import { genericErrorMessage } from '@/lib/errors';
import { RunDialog } from '@/modules/consolidation/components/run-dialog';
import { groupCan, runsQuery } from '@/modules/consolidation/groups';
import { useGroup } from '@/modules/consolidation/hooks/use-group';

/** The runs of the group, newest first (CO-FR-09): each is kept as it was computed and opens to its result. */
export function RunsPage({ groupId }: { groupId: string }) {
    const { t, i18n } = useTranslation();
    const navigate = useNavigate();
    const group = useGroup(groupId);
    const runs = useQuery(runsQuery(groupId));
    const [running, setRunning] = useState(false);

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('consolidation.runs.description')}
                </p>
                {groupCan(group, 'run') && (
                    <Button onClick={() => setRunning(true)}>
                        <PlayIcon data-icon="inline-start" />
                        {t('consolidation.runs.run')}
                    </Button>
                )}
            </div>

            {runs.isError ? (
                <FormAlert>{genericErrorMessage(runs.error, t)}</FormAlert>
            ) : runs.data === undefined ? (
                <Skeleton className="h-32 w-full" />
            ) : runs.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <SigmaIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('consolidation.runs.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('consolidation.runs.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('consolidation.runs.range')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.runs.status')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.runs.mappingVersion')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.runs.coverage')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.runs.createdAt')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {runs.data.map((run) => (
                                <TableRow key={run.id}>
                                    <TableCell className="font-medium tabular-nums">
                                        <Link
                                            to="/groups/$groupId/runs/$runId"
                                            params={{ groupId, runId: run.id }}
                                            className="underline underline-offset-2"
                                        >
                                            {formatIsoDate(
                                                run.from,
                                                i18n.language,
                                            )}{' '}
                                            –{' '}
                                            {formatIsoDate(
                                                run.to,
                                                i18n.language,
                                            )}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                run.status === 'complete'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {t(
                                                `consolidation.runs.statuses.${run.status as 'draft' | 'complete'}`,
                                            )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {run.mapping_version ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {t(
                                            `consolidation.coverage.${run.coverage_status as 'complete' | 'partial' | 'unavailable'}`,
                                        )}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {run.created_at
                                            ? new Date(
                                                  run.created_at,
                                              ).toLocaleString(i18n.language)
                                            : '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <RunDialog
                groupId={groupId}
                open={running}
                onOpenChange={setRunning}
                onCreated={(run) =>
                    void navigate({
                        to: '/groups/$groupId/runs/$runId',
                        params: { groupId, runId: run.id },
                    })
                }
            />
        </>
    );
}
