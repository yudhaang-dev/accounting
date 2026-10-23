import { useMutation, useQuery } from '@tanstack/react-query';
import {
    ChevronDownIcon,
    ChevronRightIcon,
    DownloadIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
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
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { AdjustmentsSection } from '@/modules/consolidation/components/adjustments-section';
import {
    IntercompanySection,
    TranslationSection,
} from '@/modules/consolidation/components/run-sections';
import { exportRun, runQuery } from '@/modules/consolidation/groups';

/**
 * The result of a run as it was computed (CO-FR-04, CO-FR-09, CO-FR-10). It is labelled a management roll-up
 * and says it is not a consolidated statement: nothing is translated, eliminated or adjusted yet. Each group
 * line opens to the entity accounts it adds up; accounts without a mapping stay visible as exceptions so the
 * totals still add up, and packages that are not closed or do not cover the range are flagged per entity.
 */
export function RunPage({
    groupId,
    runId,
}: {
    groupId: string;
    runId: string;
}) {
    const { t, i18n } = useTranslation();
    const run = useQuery(runQuery(groupId, runId));
    const [open, setOpen] = useState<Set<string>>(new Set());
    const download = useMutation({
        mutationFn: () => exportRun(groupId, runId),
    });

    useDocumentTitle(t('consolidation.runPage.pageTitle'));

    if (run.isError) {
        return <FormAlert>{genericErrorMessage(run.error, t)}</FormAlert>;
    }

    if (run.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const result = run.data;
    const money = (value: string) => formatMoney(value, i18n.language);
    const names = new Map(
        result.entities.map((entity) => [entity.company_id, entity.name]),
    );
    const toggle = (id: string) =>
        setOpen((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-lg font-semibold tracking-tight">
                            {formatIsoDate(result.from, i18n.language)} –{' '}
                            {formatIsoDate(result.to, i18n.language)}
                        </h2>
                        <Badge variant="outline">
                            {t(
                                `consolidation.runPage.labels.${result.label as 'management_rollup'}`,
                            )}
                        </Badge>
                        <Badge
                            variant={
                                result.status === 'complete'
                                    ? 'default'
                                    : 'secondary'
                            }
                        >
                            {t(
                                `consolidation.runs.statuses.${result.status as 'draft' | 'complete'}`,
                            )}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {t('consolidation.runPage.meta', {
                            currency: result.presentation_currency,
                            version:
                                result.mapping_version ??
                                t('consolidation.runPage.noMapping'),
                        })}
                        {result.translation_policy_version !== null &&
                            ` · ${t('consolidation.runPage.translationVersion', { version: result.translation_policy_version })}`}
                    </p>
                </div>
                <Button
                    variant="outline"
                    disabled={download.isPending}
                    onClick={() => download.mutate()}
                >
                    {download.isPending ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <DownloadIcon data-icon="inline-start" />
                    )}
                    {t('consolidation.runPage.export')}
                </Button>
            </div>

            {download.isError && (
                <FormAlert>{actionErrorMessage(download.error, t)}</FormAlert>
            )}

            {result.label !== 'consolidated' && (
                <Alert>
                    <TriangleAlertIcon aria-hidden />
                    <AlertTitle>
                        {t('consolidation.runPage.notFormalTitle')}
                    </AlertTitle>
                    <AlertDescription>
                        {t('consolidation.runPage.notFormal')}
                    </AlertDescription>
                </Alert>
            )}

            {result.status === 'draft' && (
                <Alert>
                    <TriangleAlertIcon aria-hidden />
                    <AlertTitle>
                        {t('consolidation.runPage.draftTitle')}
                    </AlertTitle>
                    <AlertDescription>
                        {t('consolidation.runPage.draft')}
                    </AlertDescription>
                </Alert>
            )}

            {!result.balanced && (
                <FormAlert>{t('consolidation.runPage.unbalanced')}</FormAlert>
            )}

            <section
                className="flex flex-col gap-2"
                aria-labelledby="run-entities"
            >
                <h3 id="run-entities" className="text-sm font-medium">
                    {t('consolidation.runPage.entities')}
                </h3>
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('consolidation.runPage.company')}
                                </TableHead>
                                <TableHead>
                                    {t(
                                        'consolidation.runPage.functionalCurrency',
                                    )}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.runPage.books')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.runs.coverage')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {result.entities.map((entity) => (
                                <TableRow key={entity.company_id}>
                                    <TableCell className="font-medium">
                                        {entity.name}
                                    </TableCell>
                                    <TableCell>
                                        {entity.functional_currency}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                entity.book_status === 'closed'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {t(
                                                `consolidation.runPage.bookStatuses.${entity.book_status as 'open' | 'soft_closed' | 'closed' | 'no_periods'}`,
                                            )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                entity.coverage_status ===
                                                'complete'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {t(
                                                `consolidation.coverage.${entity.coverage_status as 'complete' | 'partial' | 'unavailable'}`,
                                            )}
                                        </Badge>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </section>

            <TranslationSection run={result} />

            <section
                className="flex flex-col gap-2"
                aria-labelledby="run-lines"
            >
                <h3 id="run-lines" className="text-sm font-medium">
                    {t('consolidation.runPage.lines')}
                </h3>
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8" />
                                <TableHead>
                                    {t('consolidation.accounts.code')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.accounts.name')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('consolidation.runPage.debit')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('consolidation.runPage.credit')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('consolidation.runPage.closingDebit')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('consolidation.runPage.closingCredit')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {result.lines.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="text-center text-muted-foreground"
                                    >
                                        {t('consolidation.runPage.noLines')}
                                    </TableCell>
                                </TableRow>
                            )}
                            {result.lines.map((line) => {
                                const expanded = open.has(
                                    line.group_account_id,
                                );

                                return (
                                    <Fragment key={line.group_account_id}>
                                        <TableRow>
                                            <TableCell>
                                                <Button
                                                    size="icon-sm"
                                                    variant="ghost"
                                                    aria-expanded={expanded}
                                                    aria-label={t(
                                                        'consolidation.runPage.sourcesOf',
                                                        { name: line.name },
                                                    )}
                                                    onClick={() =>
                                                        toggle(
                                                            line.group_account_id,
                                                        )
                                                    }
                                                >
                                                    {expanded ? (
                                                        <ChevronDownIcon />
                                                    ) : (
                                                        <ChevronRightIcon />
                                                    )}
                                                </Button>
                                            </TableCell>
                                            <TableCell className="font-mono">
                                                {line.code}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {line.name}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {money(line.debit)}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {money(line.credit)}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {money(line.closing_debit)}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {money(line.closing_credit)}
                                            </TableCell>
                                        </TableRow>
                                        {expanded &&
                                            line.sources.map((source) => (
                                                <TableRow
                                                    key={`${source.company_id}:${source.account_id}`}
                                                    className="bg-muted/40 text-sm"
                                                >
                                                    <TableCell />
                                                    <TableCell className="font-mono text-muted-foreground">
                                                        {source.code}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {source.name} ·{' '}
                                                        {names.get(
                                                            source.company_id,
                                                        ) ?? source.company_id}
                                                        {source.kind ===
                                                            'translation_difference' &&
                                                            ` · ${t('consolidation.runPage.translationDifference')}`}
                                                    </TableCell>
                                                    <TableCell className="text-end tabular-nums">
                                                        {money(source.debit)}
                                                    </TableCell>
                                                    <TableCell className="text-end tabular-nums">
                                                        {money(source.credit)}
                                                    </TableCell>
                                                    <TableCell className="text-end tabular-nums">
                                                        {money(
                                                            source.closing_debit,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-end tabular-nums">
                                                        {money(
                                                            source.closing_credit,
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        {expanded &&
                                            line.eliminations.map(
                                                (elimination) => (
                                                    <TableRow
                                                        key={`${elimination.match_id}:${elimination.kind}:${elimination.company_id}`}
                                                        className="bg-muted/40 text-sm"
                                                    >
                                                        <TableCell />
                                                        <TableCell />
                                                        <TableCell className="text-muted-foreground">
                                                            {t(
                                                                `consolidation.runPage.eliminationKinds.${elimination.kind as 'elimination'}`,
                                                            )}
                                                            {elimination.company_id &&
                                                                ` · ${names.get(elimination.company_id) ?? elimination.company_id}`}
                                                        </TableCell>
                                                        <TableCell className="text-end tabular-nums">
                                                            {money(
                                                                elimination.debit,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-end tabular-nums">
                                                            {money(
                                                                elimination.credit,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-end tabular-nums">
                                                            {money(
                                                                elimination.closing_debit,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-end tabular-nums">
                                                            {money(
                                                                elimination.closing_credit,
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        {expanded &&
                                            line.adjustments.map(
                                                (adjustment) => (
                                                    <TableRow
                                                        key={`${adjustment.adjustment_id}:${line.group_account_id}`}
                                                        className="bg-muted/40 text-sm"
                                                    >
                                                        <TableCell />
                                                        <TableCell />
                                                        <TableCell className="text-muted-foreground">
                                                            {t(
                                                                'consolidation.runPage.adjustment',
                                                            )}{' '}
                                                            ·{' '}
                                                            {adjustment.source}
                                                        </TableCell>
                                                        <TableCell className="text-end tabular-nums">
                                                            {money(
                                                                adjustment.debit,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-end tabular-nums">
                                                            {money(
                                                                adjustment.credit,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-end tabular-nums">
                                                            {money(
                                                                adjustment.closing_debit,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-end tabular-nums">
                                                            {money(
                                                                adjustment.closing_credit,
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                    </Fragment>
                                );
                            })}
                            <TableRow className="font-medium">
                                <TableCell />
                                <TableCell colSpan={2}>
                                    {t('consolidation.runPage.total')}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(result.totals.all.debit)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(result.totals.all.credit)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(result.totals.all.closing_debit)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(result.totals.all.closing_credit)}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>

            <IntercompanySection run={result} />

            <AdjustmentsSection
                groupId={groupId}
                runId={runId}
                currency={result.presentation_currency}
            />

            {result.exceptions.length > 0 && (
                <section
                    className="flex flex-col gap-2"
                    aria-labelledby="run-exceptions"
                >
                    <h3 id="run-exceptions" className="text-sm font-medium">
                        {t('consolidation.runPage.exceptions')}
                    </h3>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('consolidation.runPage.exceptionsDescription')}
                    </p>
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('consolidation.runPage.company')}
                                    </TableHead>
                                    <TableHead>
                                        {t('consolidation.accounts.code')}
                                    </TableHead>
                                    <TableHead>
                                        {t('consolidation.accounts.name')}
                                    </TableHead>
                                    <TableHead>
                                        {t('consolidation.accounts.class')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t(
                                            'consolidation.runPage.closingDebit',
                                        )}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t(
                                            'consolidation.runPage.closingCredit',
                                        )}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {result.exceptions.map((exception) => (
                                    <TableRow
                                        key={`${exception.company_id}:${exception.account_id}`}
                                    >
                                        <TableCell>
                                            {names.get(exception.company_id) ??
                                                exception.company_id}
                                        </TableCell>
                                        <TableCell className="font-mono">
                                            {exception.code}
                                        </TableCell>
                                        <TableCell>{exception.name}</TableCell>
                                        <TableCell>
                                            {t(
                                                `consolidation.classes.${exception.class as 'asset'}`,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {money(exception.closing_debit)}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {money(exception.closing_credit)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            )}
        </>
    );
}
