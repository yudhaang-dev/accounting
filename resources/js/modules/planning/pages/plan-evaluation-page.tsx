import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ArrowLeftIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { formatMoney } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import {
    evaluationQuery,
    planQuery,
    type EvaluationCell,
    type EvaluationVariance,
} from '@/modules/planning/plans';
import { lineDimensionsQuery } from '@/modules/transaction-entry/entries';

const ALL = 'all';

const FAVORABLE = 'text-emerald-700 dark:text-emerald-400';
const UNFAVORABLE = 'text-destructive';

/** Realised minus planned with its share of the plan; the tone follows the favorable rule (RP-FR-12), none for no difference. */
function Variance({
    variance,
    locale,
}: {
    variance: EvaluationVariance;
    locale: string;
}) {
    const { t } = useTranslation();

    if (variance === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span
            className={
                variance.favorable === null
                    ? undefined
                    : variance.favorable
                      ? FAVORABLE
                      : UNFAVORABLE
            }
            title={
                variance.favorable === null
                    ? undefined
                    : t(
                          variance.favorable
                              ? 'plans.evaluation.favorable'
                              : 'plans.evaluation.unfavorable',
                      )
            }
        >
            {formatMoney(variance.amount, locale)}
            {variance.percentage !== null && (
                <span className="ml-1 text-xs">
                    ({formatMoney(variance.percentage, locale)}%)
                </span>
            )}
        </span>
    );
}

/** A period difference of the result, where positive is favorable; none when the version does not exist. */
function Signed({ value, locale }: { value: string | null; locale: string }) {
    if (value === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const zero = Number(value) === 0;

    return (
        <span
            className={
                zero
                    ? undefined
                    : value.startsWith('-')
                      ? UNFAVORABLE
                      : FAVORABLE
            }
        >
            {formatMoney(value, locale)}
        </span>
    );
}

function Amount({ value, locale }: { value: string | null; locale: string }) {
    return value === null ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <>{formatMoney(value, locale)}</>
    );
}

/**
 * An activity plan against the ledger of its scope: the first published version (initial estimate), the last
 * or a chosen one (revision) and the actual, per account and period, with the signed cumulative variance
 * (RP-FR-11, RP-FR-12). The system only presents the numbers; reading them is the reader's task.
 */
export function PlanEvaluationPage({
    companyId,
    planId,
}: {
    companyId: string;
    planId: string;
}) {
    const { t, i18n } = useTranslation();
    const [revisionId, setRevisionId] = useState('');
    const [periodId, setPeriodId] = useState(ALL);
    const plan = useQuery(planQuery(companyId, planId));
    const evaluation = useQuery(
        evaluationQuery(companyId, planId, revisionId || null),
    );
    const dimensions = useQuery(lineDimensionsQuery(companyId));

    useDocumentTitle(
        t('plans.evaluation.pageTitle', { name: plan.data?.name ?? '' }),
    );

    const failure = plan.error ?? evaluation.error;

    if (failure) {
        return <FormAlert>{genericErrorMessage(failure, t)}</FormAlert>;
    }

    if (plan.data === undefined || evaluation.data === undefined) {
        return <Skeleton className="h-96 w-full" />;
    }

    const data = evaluation.data;
    const locale = i18n.language;
    const scope = (dimensions.data ?? [])
        .flatMap((type) =>
            type.values.map((value) => ({
                id: value.id,
                label: `${type.label}: ${value.name}`,
            })),
        )
        .find((value) => value.id === data.plan.scope_dimension_value_id);
    const cellOf = (
        row: (typeof data.rows)[number],
    ): EvaluationCell | undefined =>
        periodId === ALL
            ? row.total
            : row.cells.find((cell) => cell.period_id === periodId);
    const visible = data.rows.flatMap((row) => {
        const cell = cellOf(row);

        return cell !== undefined &&
            (cell.initial !== null ||
                cell.revision !== null ||
                Number(cell.actual) !== 0)
            ? [{ row, cell }]
            : [];
    });
    const periodItems = [
        { value: ALL, label: t('plans.evaluation.allPeriods') },
        ...data.periods.map((period) => ({
            value: period.id,
            label: period.name,
        })),
    ];

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('plans.evaluation.pageTitle', {
                            name: data.plan.name,
                        })}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('plans.evaluation.description')}
                    </p>
                </div>
                <Button
                    variant="outline"
                    render={
                        <Link to="/w/$companyId/plans" params={{ companyId }} />
                    }
                >
                    <ArrowLeftIcon data-icon="inline-start" />
                    {t('plans.evaluation.back')}
                </Button>
            </div>

            <dl className="grid max-w-3xl grid-cols-[auto_1fr] items-center gap-x-6 gap-y-2 text-sm">
                <dt className="text-muted-foreground">
                    {t('plans.evaluation.period')}
                </dt>
                <dd>
                    {formatIsoDate(data.plan.starts_on, locale)} –{' '}
                    {formatIsoDate(data.plan.ends_on, locale)}
                </dd>
                <dt className="text-muted-foreground">
                    {t('plans.evaluation.scope')}
                </dt>
                <dd>{scope?.label ?? t('plans.plan.scopeWhole')}</dd>
                {data.plan.objective && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('plans.evaluation.objective')}
                        </dt>
                        <dd>{data.plan.objective}</dd>
                    </>
                )}
                <dt className="text-muted-foreground">
                    {t('plans.evaluation.initial')}
                </dt>
                <dd>{data.versions.initial?.name ?? '—'}</dd>
                <dt className="text-muted-foreground">
                    {t('plans.evaluation.revisionVersion')}
                </dt>
                <dd className="max-w-xs">
                    <SearchableSelect
                        items={data.available_versions.map((version) => ({
                            value: version.id,
                            label: version.name,
                        }))}
                        value={revisionId || data.versions.revision?.id || ''}
                        onValueChange={setRevisionId}
                        placeholder={t('plans.evaluation.revisionLatest')}
                        emptyText={t('common.noMatch')}
                        disabled={data.available_versions.length === 0}
                        aria-label={t('plans.evaluation.revisionVersion')}
                    />
                </dd>
            </dl>

            {data.restricted && (
                <FormAlert>{t('plans.evaluation.restricted')}</FormAlert>
            )}
            {data.versions.initial === null && (
                <FormAlert>
                    {t('plans.evaluation.noVersion')}.{' '}
                    {t('plans.evaluation.noVersionDescription')}
                </FormAlert>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>{t('plans.evaluation.summary')}</CardTitle>
                    <CardDescription>
                        {t('plans.evaluation.result')} ·{' '}
                        {t('plans.evaluation.currency', {
                            code: data.functional_currency,
                        })}
                    </CardDescription>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('plans.evaluation.period')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('plans.evaluation.actual')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('plans.evaluation.initial')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('plans.evaluation.revision')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('plans.evaluation.varianceInitial')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('plans.evaluation.varianceRevision')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('plans.evaluation.cumulativeInitial')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('plans.evaluation.cumulativeRevision')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.periods.map((period) => (
                                <TableRow key={period.id}>
                                    <TableCell className="font-medium">
                                        {period.name}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Amount
                                            value={period.result.actual}
                                            locale={locale}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Amount
                                            value={period.result.initial}
                                            locale={locale}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Amount
                                            value={period.result.revision}
                                            locale={locale}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Signed
                                            value={period.variance.initial}
                                            locale={locale}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Signed
                                            value={period.variance.revision}
                                            locale={locale}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Signed
                                            value={
                                                period.cumulative_variance
                                                    .initial
                                            }
                                            locale={locale}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Signed
                                            value={
                                                period.cumulative_variance
                                                    .revision
                                            }
                                            locale={locale}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('plans.evaluation.byAccount')}</CardTitle>
                    <div className="max-w-xs">
                        <SearchableSelect
                            items={periodItems}
                            value={periodId}
                            onValueChange={(value) => setPeriodId(value || ALL)}
                            emptyText={t('common.noMatch')}
                            aria-label={t('plans.evaluation.showPeriod')}
                        />
                    </div>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    {visible.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('plans.evaluation.noRows')}
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('plans.evaluation.account')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('plans.evaluation.actual')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('plans.evaluation.initial')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('plans.evaluation.varianceInitial')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('plans.evaluation.revision')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('plans.evaluation.varianceRevision')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.map(({ row, cell }) => (
                                    <TableRow key={row.account_id}>
                                        <TableCell>
                                            <span className="text-muted-foreground tabular-nums">
                                                {row.code}
                                            </span>{' '}
                                            {row.name}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <Amount
                                                value={cell.actual}
                                                locale={locale}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <Amount
                                                value={cell.initial}
                                                locale={locale}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <Variance
                                                variance={
                                                    cell.variances.initial
                                                }
                                                locale={locale}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <Amount
                                                value={cell.revision}
                                                locale={locale}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <Variance
                                                variance={
                                                    cell.variances.revision
                                                }
                                                locale={locale}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </>
    );
}
