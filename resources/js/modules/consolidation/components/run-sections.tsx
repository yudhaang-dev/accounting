import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatIsoDate } from '@/lib/date';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import type { Run } from '@/modules/consolidation/groups';

/** What a company's accounts were translated at: the rates read for each purpose, and the difference that is left (CO-FR-06). */
export function TranslationSection({ run }: { run: Run }) {
    const { t, i18n } = useTranslation();
    const translated = run.entities.filter(
        (entity) => entity.translation !== null,
    );

    if (translated.length === 0) {
        return null;
    }

    return (
        <section
            className="flex flex-col gap-3"
            aria-labelledby="run-translation"
        >
            <h3 id="run-translation" className="text-sm font-medium">
                {t('consolidation.runPage.translation.title')}
            </h3>
            <p className="max-w-2xl text-sm text-muted-foreground">
                {t('consolidation.runPage.translation.description')}
            </p>
            {translated.map((entity) => {
                const translation = entity.translation!;

                return (
                    <div
                        key={entity.company_id}
                        className="flex flex-col gap-2 rounded-lg border p-3"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{entity.name}</span>
                            <Badge variant="outline">
                                {translation.functional_currency} →{' '}
                                {translation.presentation_currency}
                            </Badge>
                            <span className="text-sm text-muted-foreground">
                                {t('consolidation.runPage.translation.policy', {
                                    version: translation.policy_version,
                                })}
                            </span>
                        </div>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'consolidation.runPage.translation.purpose',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'consolidation.translation.rates.type',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'consolidation.runPage.translation.requested',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'consolidation.runPage.translation.effective',
                                            )}
                                        </TableHead>
                                        <TableHead className="text-end">
                                            {t(
                                                'consolidation.translation.rates.rate',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'consolidation.translation.rates.version',
                                            )}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {translation.rates.map((rate) => (
                                        <TableRow
                                            key={`${rate.purpose}:${rate.requested_date}`}
                                        >
                                            <TableCell>
                                                {t(
                                                    `consolidation.runPage.translation.purposes.${rate.purpose as 'closing'}`,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {rate.rate_type}
                                            </TableCell>
                                            <TableCell>
                                                {formatIsoDate(
                                                    rate.requested_date,
                                                    i18n.language,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatIsoDate(
                                                    rate.effective_date,
                                                    i18n.language,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {formatDecimal(
                                                    rate.rate,
                                                    i18n.language,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {rate.version}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('consolidation.runPage.translation.difference', {
                                opening: formatMoney(
                                    translation.difference.opening,
                                    i18n.language,
                                ),
                                closing: formatMoney(
                                    translation.difference.closing,
                                    i18n.language,
                                ),
                            })}
                        </p>
                    </div>
                );
            })}
        </section>
    );
}

/**
 * The intragroup pairs the run looked at, by pair of companies: those that agreed within the tolerance were
 * eliminated, and the rest stand as exceptions with the reason, never removed on their own (CO-FR-07).
 */
export function IntercompanySection({ run }: { run: Run }) {
    const { t, i18n } = useTranslation();
    const money = (value: string) => formatMoney(value, i18n.language);
    const names = new Map(
        run.entities.map((entity) => [entity.company_id, entity.name]),
    );
    const name = (id: string) => names.get(id) ?? id;

    if (
        run.intercompany.length === 0 &&
        run.unassigned_eliminations.length === 0
    ) {
        return null;
    }

    return (
        <section
            className="flex flex-col gap-2"
            aria-labelledby="run-intercompany"
        >
            <h3 id="run-intercompany" className="text-sm font-medium">
                {t('consolidation.runPage.intercompany.title')}
            </h3>
            <p className="max-w-2xl text-sm text-muted-foreground">
                {t('consolidation.runPage.intercompany.description', {
                    tolerance: money(run.elimination_tolerance),
                    currency: run.presentation_currency,
                })}
            </p>
            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>
                                {t('consolidation.runPage.intercompany.pair')}
                            </TableHead>
                            <TableHead>
                                {t('consolidation.runPage.intercompany.kind')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t(
                                    'consolidation.runPage.intercompany.amountA',
                                )}
                            </TableHead>
                            <TableHead className="text-end">
                                {t(
                                    'consolidation.runPage.intercompany.amountB',
                                )}
                            </TableHead>
                            <TableHead className="text-end">
                                {t(
                                    'consolidation.runPage.intercompany.difference',
                                )}
                            </TableHead>
                            <TableHead>
                                {t('consolidation.runPage.intercompany.status')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {run.intercompany.map((match) => (
                            <TableRow key={match.id}>
                                <TableCell className="font-medium">
                                    {name(match.company_a_id)} ·{' '}
                                    {name(match.company_b_id)}
                                </TableCell>
                                <TableCell>
                                    {t(
                                        `consolidation.runPage.intercompany.kinds.${match.kind as 'balance'}`,
                                    )}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(match.amount_a)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(match.amount_b)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(match.difference)}
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant={
                                            match.status === 'matched'
                                                ? 'default'
                                                : 'destructive'
                                        }
                                    >
                                        {match.status === 'matched'
                                            ? t(
                                                  'consolidation.runPage.intercompany.matched',
                                              )
                                            : t(
                                                  `consolidation.runPage.intercompany.reasons.${(match.reason ?? 'difference') as 'difference'}`,
                                              )}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {run.unassigned_eliminations.length > 0 && (
                <div className="flex flex-col gap-2">
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('consolidation.runPage.intercompany.leftover')}
                    </p>
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t(
                                            'consolidation.runPage.intercompany.pair',
                                        )}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('consolidation.runPage.debit')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('consolidation.runPage.credit')}
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
                                {run.unassigned_eliminations.map((row) => (
                                    <TableRow
                                        key={`${row.match_id}:${row.kind}`}
                                    >
                                        <TableCell>
                                            {row.company_id
                                                ? name(row.company_id)
                                                : '—'}
                                            {row.counterparty_company_id &&
                                                ` · ${name(row.counterparty_company_id)}`}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {money(row.debit)}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {money(row.credit)}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {money(row.closing_debit)}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {money(row.closing_credit)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            )}
        </section>
    );
}
