import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { CameraIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
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
import { formatIsoDate } from '@/lib/date';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    SnapshotDialog,
    type SnapshotTarget,
} from '@/modules/reporting/components/snapshot-dialogs';
import { reportDefinitionQuery } from '@/modules/reporting/report-definitions';
import { ResultView } from '@/modules/reporting/pages/report-viewer-page';
import {
    needsRecompute,
    snapshotQuery,
    snapshotsQuery,
    type ReportSnapshotSummary,
} from '@/modules/reporting/snapshots';

const stampOf = (value: string, locale: string) =>
    new Date(value).toLocaleString(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

/** The dates a snapshot covers: one date or a range. */
function rangeOf(parameters: Record<string, unknown>, locale: string) {
    const from = parameters.from as string | undefined;
    const to = parameters.to as string | undefined;

    if (!from || !to) {
        return '—';
    }

    return from === to
        ? formatIsoDate(to, locale)
        : `${formatIsoDate(from, locale)} – ${formatIsoDate(to, locale)}`;
}

/** The report definition a page of snapshots belongs to: name, and the version the snapshots compare with. */
function useDefinition(companyId: string, definitionId: string) {
    return useQuery(reportDefinitionQuery(companyId, definitionId));
}

/**
 * The snapshots of one report definition, newest first (RP-FR-14). A snapshot published from an older
 * version of the definition is marked, as recalculating it would give other numbers (X-AC-04).
 */
export function SnapshotsPage({
    companyId,
    definitionId,
    canPublish,
}: {
    companyId: string;
    definitionId: string;
    canPublish: boolean;
}) {
    const { t, i18n } = useTranslation();
    const definition = useDefinition(companyId, definitionId);
    const snapshots = useQuery(snapshotsQuery(companyId, definitionId));
    const [target, setTarget] = useState<SnapshotTarget | null>(null);

    useDocumentTitle(t('snapshots.pageTitle'));

    const current = definition.data?.current_version;

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('snapshots.title', {
                            name: definition.data?.name ?? '…',
                        })}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('snapshots.description')}
                    </p>
                </div>
                <Link
                    to="/w/$companyId/reports/$definitionId"
                    params={{ companyId, definitionId }}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    {t('snapshots.backToReport')}
                </Link>
            </div>

            {snapshots.isError ? (
                <FormAlert>{genericErrorMessage(snapshots.error, t)}</FormAlert>
            ) : snapshots.data === undefined ? (
                <Skeleton className="h-40 w-full" />
            ) : snapshots.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <CameraIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('snapshots.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t(
                                canPublish
                                    ? 'snapshots.emptyCanPublish'
                                    : 'snapshots.emptyReadOnly',
                            )}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('snapshots.number')}</TableHead>
                                <TableHead>
                                    {t('snapshots.publishedAt')}
                                </TableHead>
                                <TableHead>{t('snapshots.range')}</TableHead>
                                <TableHead className="text-end">
                                    {t('snapshots.definitionVersion')}
                                </TableHead>
                                <TableHead>{t('snapshots.reason')}</TableHead>
                                <TableHead>
                                    <span className="sr-only">
                                        {t('snapshots.actions')}
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {snapshots.data.map((snapshot) => (
                                <SnapshotRow
                                    key={snapshot.id}
                                    companyId={companyId}
                                    definitionId={definitionId}
                                    snapshot={snapshot}
                                    all={snapshots.data}
                                    stale={
                                        current !== undefined &&
                                        needsRecompute(snapshot, current)
                                    }
                                    locale={i18n.language}
                                    canPublish={canPublish}
                                    onRecompute={() =>
                                        setTarget({
                                            kind: 'recompute',
                                            snapshotId: snapshot.id,
                                            definitionId,
                                        })
                                    }
                                />
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <SnapshotDialog
                companyId={companyId}
                target={target}
                onClose={() => setTarget(null)}
            />
        </>
    );
}

function SnapshotRow({
    companyId,
    definitionId,
    snapshot,
    all,
    stale,
    locale,
    canPublish,
    onRecompute,
}: {
    companyId: string;
    definitionId: string;
    snapshot: ReportSnapshotSummary;
    all: ReportSnapshotSummary[];
    stale: boolean;
    locale: string;
    canPublish: boolean;
    onRecompute: () => void;
}) {
    const { t } = useTranslation();
    const origin = all.find(
        (other) => other.id === snapshot.recomputed_from_id,
    );

    return (
        <TableRow>
            <TableCell>
                <Link
                    to="/w/$companyId/reports/$definitionId/snapshots/$snapshotId"
                    params={{
                        companyId,
                        definitionId,
                        snapshotId: snapshot.id,
                    }}
                    className="font-medium underline-offset-4 hover:underline"
                >
                    {t('snapshots.numberOf', { number: snapshot.sequence })}
                </Link>
            </TableCell>
            <TableCell>{stampOf(snapshot.published_at, locale)}</TableCell>
            <TableCell>{rangeOf(snapshot.parameters, locale)}</TableCell>
            <TableCell className="text-end tabular-nums">
                {snapshot.definition_version}
                {stale && (
                    <Badge variant="outline" className="ms-2">
                        {t('snapshots.stale')}
                    </Badge>
                )}
            </TableCell>
            <TableCell className="text-muted-foreground">
                {origin && (
                    <div className="text-xs">
                        {t('snapshots.recomputedFrom', {
                            number: origin.sequence,
                        })}
                    </div>
                )}
                {snapshot.reason}
            </TableCell>
            <TableCell className="text-end">
                {canPublish && (
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label={t('snapshots.recomputeNamed', {
                            number: snapshot.sequence,
                        })}
                        onClick={onRecompute}
                    >
                        {t('snapshots.recompute.button')}
                    </Button>
                )}
            </TableCell>
        </TableRow>
    );
}

/**
 * One snapshot as it was published: the parameters, the versions it read, the data it covered and the
 * values, unchanged by later edits of the definition or the mapping (RP-AC-08). A user limited to some
 * dimension values cannot read the values and is told why.
 */
export function SnapshotDetailPage({
    companyId,
    definitionId,
    snapshotId,
    canPublish,
}: {
    companyId: string;
    definitionId: string;
    snapshotId: string;
    canPublish: boolean;
}) {
    const { t, i18n } = useTranslation();
    const definition = useDefinition(companyId, definitionId);
    const snapshot = useQuery(snapshotQuery(companyId, snapshotId));
    const [target, setTarget] = useState<SnapshotTarget | null>(null);

    useDocumentTitle(t('snapshots.pageTitle'));

    const back = (
        <Link
            to="/w/$companyId/reports/$definitionId/snapshots"
            params={{ companyId, definitionId }}
            className={buttonVariants({ variant: 'outline' })}
        >
            {t('snapshots.backToList')}
        </Link>
    );

    if (snapshot.isError) {
        return (
            <>
                {isApiError(snapshot.error) && snapshot.error.status === 403 ? (
                    <Alert role="status">
                        <AlertTitle>{t('snapshots.scopeTitle')}</AlertTitle>
                        <AlertDescription>
                            {t('snapshots.scope')}
                        </AlertDescription>
                    </Alert>
                ) : (
                    <FormAlert>
                        {genericErrorMessage(snapshot.error, t)}
                    </FormAlert>
                )}
                <div>{back}</div>
            </>
        );
    }

    if (snapshot.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const data = snapshot.data;
    const stale =
        definition.data !== undefined &&
        needsRecompute(data, definition.data.current_version);
    const boundary = data.data_boundary as Record<string, unknown>;
    const filters = Object.entries(data.parameters).filter(
        ([key, value]) =>
            !['from', 'to', 'as_of'].includes(key) &&
            value !== null &&
            value !== '' &&
            !(Array.isArray(value) && value.length === 0) &&
            !(
                typeof value === 'object' &&
                !Array.isArray(value) &&
                Object.keys(value as object).length === 0
            ),
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('snapshots.detailTitle', {
                                name: definition.data?.name ?? '…',
                                number: data.sequence,
                            })}
                        </h1>
                        {stale && (
                            <Badge variant="outline">
                                {t('snapshots.stale')}
                            </Badge>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {t('snapshots.publishedOn', {
                            at: stampOf(data.published_at, i18n.language),
                        })}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {back}
                    {canPublish && (
                        <Button
                            onClick={() =>
                                setTarget({
                                    kind: 'recompute',
                                    snapshotId: data.id,
                                    definitionId,
                                })
                            }
                        >
                            {t('snapshots.recompute.button')}
                        </Button>
                    )}
                </div>
            </div>

            {stale && (
                <Alert role="status">
                    <AlertTitle>{t('snapshots.staleTitle')}</AlertTitle>
                    <AlertDescription>
                        {t('snapshots.staleDescription', {
                            published: data.definition_version,
                            current: definition.data?.current_version,
                        })}
                    </AlertDescription>
                </Alert>
            )}

            <dl className="grid gap-x-6 gap-y-2 rounded-lg border p-4 text-sm sm:grid-cols-2">
                <Item label={t('snapshots.range')}>
                    {rangeOf(data.parameters, i18n.language)}
                </Item>
                <Item label={t('snapshots.definitionVersion')}>
                    {data.definition_version}
                </Item>
                <Item label={t('snapshots.mappingVersion')}>
                    {String(data.mapping_versions.cash_equivalents ?? '—')}
                </Item>
                <Item label={t('snapshots.computedAt')}>
                    {typeof boundary.computed_at === 'string'
                        ? stampOf(boundary.computed_at, i18n.language)
                        : '—'}
                </Item>
                {data.reason && (
                    <Item label={t('snapshots.reason')}>{data.reason}</Item>
                )}
                {data.recomputed_from_id && (
                    <Item label={t('snapshots.recomputedLabel')}>
                        <Link
                            to="/w/$companyId/reports/$definitionId/snapshots/$snapshotId"
                            params={{
                                companyId,
                                definitionId,
                                snapshotId: data.recomputed_from_id,
                            }}
                            className="underline underline-offset-4"
                        >
                            {t('snapshots.openOriginal')}
                        </Link>
                    </Item>
                )}
                {filters.length > 0 && (
                    <Item label={t('snapshots.filters')}>
                        <ul>
                            {filters.map(([key, value]) => (
                                <li key={key}>
                                    <span className="font-mono text-xs">
                                        {key}
                                    </span>
                                    {': '}
                                    {typeof value === 'object'
                                        ? JSON.stringify(value)
                                        : String(value)}
                                </li>
                            ))}
                        </ul>
                    </Item>
                )}
            </dl>

            <ResultView result={data.result} locale={i18n.language} />

            <SnapshotDialog
                companyId={companyId}
                target={target}
                onClose={() => setTarget(null)}
            />
        </>
    );
}

function Item({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}
