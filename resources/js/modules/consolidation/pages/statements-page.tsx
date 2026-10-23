import { useMutation, useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ArrowLeftIcon, DownloadIcon, TriangleAlertIcon } from 'lucide-react';
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
import { formatMoney, sumDecimals } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import {
    exportStatements,
    statementsQuery,
    type Statements,
} from '@/modules/consolidation/groups';

type Section = {
    lines: Statements['balance_sheet']['assets']['lines'];
    total: string;
};

/** The statements of a run (CO-FR-10), read from the run, so they carry its label and whether it is formal. */
export function StatementsPage({
    groupId,
    runId,
}: {
    groupId: string;
    runId: string;
}) {
    const { t, i18n } = useTranslation();
    const statements = useQuery(statementsQuery(groupId, runId));
    const download = useMutation({
        mutationFn: () => exportStatements(groupId, runId),
    });

    useDocumentTitle(t('consolidation.statements.pageTitle'));

    if (statements.isError) {
        return (
            <FormAlert>{genericErrorMessage(statements.error, t)}</FormAlert>
        );
    }

    if (statements.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const data = statements.data;
    const money = (value: string) => formatMoney(value, i18n.language);
    const formal = data.label === 'consolidated' && data.formal;
    const names = new Map(
        data.reconciliation.entities.map((entity) => [
            entity.company_id,
            entity.name,
        ]),
    );
    const name = (id: string | null) => (id ? (names.get(id) ?? id) : '—');

    const amountTable = (title: string, section: Section) => (
        <div className="flex flex-col gap-1">
            <h4 className="text-sm font-medium">{title}</h4>
            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableBody>
                        {section.lines.map((line) => (
                            <TableRow key={line.group_account_id}>
                                <TableCell className="w-24 font-mono">
                                    {line.code}
                                </TableCell>
                                <TableCell>{line.name}</TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(line.amount)}
                                </TableCell>
                            </TableRow>
                        ))}
                        <TableRow className="font-medium">
                            <TableCell />
                            <TableCell>
                                {t('consolidation.runPage.total')}
                            </TableCell>
                            <TableCell className="text-end tabular-nums">
                                {money(section.total)}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    );

    const row = (label: string, value: string, strong = false) => (
        <TableRow className={strong ? 'font-medium' : undefined}>
            <TableCell>{label}</TableCell>
            <TableCell className="text-end tabular-nums">
                {money(value)}
            </TableCell>
        </TableRow>
    );

    const sheet = data.balance_sheet;
    const income = data.income_statement;
    const flow = data.cash_flow;
    const elim = data.eliminations;
    const rec = data.reconciliation;

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="w-fit"
                        render={
                            <Link
                                to="/groups/$groupId/runs/$runId"
                                params={{ groupId, runId }}
                            />
                        }
                    >
                        <ArrowLeftIcon data-icon="inline-start" />
                        {t('consolidation.statements.back')}
                    </Button>
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-lg font-semibold tracking-tight">
                            {formatIsoDate(data.from, i18n.language)} –{' '}
                            {formatIsoDate(data.to, i18n.language)}
                        </h2>
                        <Badge variant={formal ? 'default' : 'outline'}>
                            {t(
                                `consolidation.runPage.labels.${data.label as 'management_rollup'}`,
                            )}
                        </Badge>
                        {data.status === 'draft' && (
                            <Badge variant="secondary">
                                {t('consolidation.runs.statuses.draft')}
                            </Badge>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {t('consolidation.statements.currency', {
                            currency: data.presentation_currency,
                        })}
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

            {!formal && (
                <Alert>
                    <TriangleAlertIcon aria-hidden />
                    <AlertTitle>
                        {t('consolidation.statements.notFormalTitle')}
                    </AlertTitle>
                    <AlertDescription>
                        {data.label === 'consolidated'
                            ? t('consolidation.statements.draft')
                            : t('consolidation.statements.rollup')}
                    </AlertDescription>
                </Alert>
            )}

            <section
                className="flex flex-col gap-3"
                aria-labelledby="statement-balance"
            >
                <h3 id="statement-balance" className="text-base font-semibold">
                    {t('consolidation.statements.balanceSheet')}
                </h3>
                {amountTable(
                    t('consolidation.statements.assets'),
                    sheet.assets,
                )}
                {amountTable(
                    t('consolidation.statements.liabilities'),
                    sheet.liabilities,
                )}
                {amountTable(
                    t('consolidation.statements.equity'),
                    sheet.equity,
                )}
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableBody>
                            {row(
                                t(
                                    'consolidation.statements.currentYearEarnings',
                                ),
                                sheet.current_year_earnings,
                            )}
                            {row(
                                t(
                                    'consolidation.statements.totalLiabilitiesAndEquity',
                                ),
                                sheet.total_liabilities_and_equity,
                                true,
                            )}
                        </TableBody>
                    </Table>
                </div>
                {!sheet.balanced && (
                    <FormAlert>
                        {t('consolidation.statements.sheetUnbalanced', {
                            difference: money(sheet.difference),
                        })}
                    </FormAlert>
                )}
            </section>

            <section
                className="flex flex-col gap-3"
                aria-labelledby="statement-income"
            >
                <h3 id="statement-income" className="text-base font-semibold">
                    {t('consolidation.statements.incomeStatement')}
                </h3>
                {amountTable(
                    t('consolidation.statements.income'),
                    income.income,
                )}
                {amountTable(
                    t('consolidation.statements.expenses'),
                    income.expenses,
                )}
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableBody>
                            {row(
                                t('consolidation.statements.netResult'),
                                income.net_result,
                                true,
                            )}
                        </TableBody>
                    </Table>
                </div>
            </section>

            <section
                className="flex flex-col gap-3"
                aria-labelledby="statement-cash"
            >
                <div className="flex flex-wrap items-center gap-2">
                    <h3 id="statement-cash" className="text-base font-semibold">
                        {t('consolidation.statements.cashFlow')}
                    </h3>
                    <Badge
                        variant={flow.reconciled ? 'default' : 'destructive'}
                    >
                        {t(
                            flow.reconciled
                                ? 'consolidation.statements.reconciled'
                                : 'consolidation.statements.notReconciled',
                        )}
                    </Badge>
                </div>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('consolidation.statements.cashFlowDescription')}
                </p>
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableBody>
                            {row(
                                t('consolidation.statements.netResult'),
                                flow.net_result,
                            )}
                        </TableBody>
                    </Table>
                </div>
                {amountTable(
                    t('consolidation.statements.operating'),
                    flow.operating,
                )}
                {amountTable(
                    t('consolidation.statements.investing'),
                    flow.investing,
                )}
                {amountTable(
                    t('consolidation.statements.financing'),
                    flow.financing,
                )}
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableBody>
                            {row(
                                t('consolidation.statements.translationEffect'),
                                flow.translation_effect,
                            )}
                            {row(
                                t('consolidation.statements.netChange'),
                                flow.net_change,
                                true,
                            )}
                            {row(
                                t('consolidation.statements.openingCash'),
                                flow.opening_cash,
                            )}
                            {row(
                                t('consolidation.statements.closingCash'),
                                flow.closing_cash,
                                true,
                            )}
                            {!flow.reconciled &&
                                row(
                                    t(
                                        'consolidation.statements.cashDifference',
                                    ),
                                    flow.difference,
                                )}
                        </TableBody>
                    </Table>
                </div>
                {flow.unclassified.length > 0 && (
                    <Alert>
                        <TriangleAlertIcon aria-hidden />
                        <AlertTitle>
                            {t('consolidation.statements.unclassifiedTitle')}
                        </AlertTitle>
                        <AlertDescription>
                            <p>{t('consolidation.statements.unclassified')}</p>
                            <ul className="mt-1 list-disc ps-4">
                                {flow.unclassified.map((line) => (
                                    <li key={line.group_account_id}>
                                        {line.code} · {line.name} ·{' '}
                                        {money(line.amount)}
                                    </li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}
            </section>

            <section
                className="flex flex-col gap-3"
                aria-labelledby="statement-elim"
            >
                <h3 id="statement-elim" className="text-base font-semibold">
                    {t('consolidation.statements.eliminations')}
                </h3>
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t(
                                        'consolidation.runPage.intercompany.pair',
                                    )}
                                </TableHead>
                                <TableHead>
                                    {t(
                                        'consolidation.runPage.intercompany.kind',
                                    )}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t(
                                        'consolidation.runPage.intercompany.difference',
                                    )}
                                </TableHead>
                                <TableHead>
                                    {t(
                                        'consolidation.runPage.intercompany.status',
                                    )}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {elim.pairs.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="text-center text-muted-foreground"
                                    >
                                        {t('consolidation.statements.noPairs')}
                                    </TableCell>
                                </TableRow>
                            )}
                            {elim.pairs.map((pair) => (
                                <TableRow key={pair.id}>
                                    <TableCell>
                                        {name(pair.company_a_id)} ·{' '}
                                        {name(pair.company_b_id)}
                                    </TableCell>
                                    <TableCell>
                                        {t(
                                            `consolidation.runPage.intercompany.kinds.${pair.kind as 'balance'}`,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {money(pair.difference)}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                pair.status === 'matched'
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {pair.status === 'matched'
                                                ? t(
                                                      'consolidation.runPage.intercompany.matched',
                                                  )
                                                : t(
                                                      `consolidation.runPage.intercompany.reasons.${(pair.reason ?? 'difference') as 'difference'}`,
                                                  )}
                                        </Badge>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('consolidation.accounts.name')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.runPage.company')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('consolidation.runPage.debit')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('consolidation.runPage.credit')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {elim.eliminations.map((e, index) => (
                                <TableRow
                                    key={`${e.match_id}:${e.kind}:${index}`}
                                >
                                    <TableCell>
                                        {t(
                                            `consolidation.runPage.eliminationKinds.${e.kind as 'elimination'}`,
                                        )}
                                        {e.name && ` · ${e.code} ${e.name}`}
                                    </TableCell>
                                    <TableCell>{name(e.company_id)}</TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {money(e.debit)}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {money(e.credit)}
                                    </TableCell>
                                </TableRow>
                            ))}
                            <TableRow className="font-medium">
                                <TableCell colSpan={2}>
                                    {t(
                                        'consolidation.statements.totalEliminated',
                                    )}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(elim.total_eliminated.debit ?? '0')}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(elim.total_eliminated.credit ?? '0')}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
                <h4 className="text-sm font-medium">
                    {t('consolidation.statements.adjustments')}
                </h4>
                {elim.adjustments.filter((a) => a.status === 'approved')
                    .length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('consolidation.statements.noAdjustments')}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableBody>
                                {elim.adjustments
                                    .filter((a) => a.status === 'approved')
                                    .map((adjustment) => (
                                        <TableRow key={adjustment.id}>
                                            <TableCell className="font-medium">
                                                {adjustment.source}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {adjustment.reason}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {money(
                                                    sumDecimals(
                                                        adjustment.lines.map(
                                                            (line) =>
                                                                line.debit,
                                                        ),
                                                    ),
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </section>

            <section
                className="flex flex-col gap-3"
                aria-labelledby="statement-reconciliation"
            >
                <h3
                    id="statement-reconciliation"
                    className="text-base font-semibold"
                >
                    {t('consolidation.statements.reconciliation')}
                </h3>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('consolidation.statements.reconciliationDescription')}
                </p>
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('consolidation.runPage.company')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('consolidation.statements.accountsPart')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t(
                                        'consolidation.statements.translationPart',
                                    )}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t(
                                        'consolidation.statements.eliminationsPart',
                                    )}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('consolidation.statements.contribution')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rec.entities.map((entity) => (
                                <TableRow key={entity.company_id}>
                                    <TableCell className="font-medium">
                                        {entity.name}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {money(entity.accounts)}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {money(entity.translation_difference)}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {money(entity.eliminations)}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {money(entity.contribution)}
                                    </TableCell>
                                </TableRow>
                            ))}
                            <TableRow>
                                <TableCell colSpan={4}>
                                    {t(
                                        'consolidation.statements.eliminationDifferences',
                                    )}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(rec.elimination_differences)}
                                </TableCell>
                            </TableRow>
                            <TableRow>
                                <TableCell colSpan={4}>
                                    {t(
                                        'consolidation.statements.adjustmentsPart',
                                    )}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(rec.adjustments)}
                                </TableCell>
                            </TableRow>
                            <TableRow className="font-medium">
                                <TableCell colSpan={4}>
                                    {t('consolidation.runPage.total')}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(rec.total)}
                                </TableCell>
                            </TableRow>
                            <TableRow>
                                <TableCell colSpan={4}>
                                    {t('consolidation.statements.runTotal')}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(rec.run_total)}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>
        </>
    );
}
