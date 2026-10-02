import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { HistoryImportWizard } from '@/modules/general-ledger/components/history-import-wizard';
import { OpeningBalanceWizard } from '@/modules/general-ledger/components/opening-balance-wizard';
import {
    historyImportQuery,
    openingBatchQuery,
} from '@/modules/general-ledger/conversion';

/**
 * Opening balances and history of a company that starts in the middle of its life (GL-FR-11): the
 * wizard before the import, and what was imported after it. The history between the start of the
 * migrated data and the cutover is a second wizard, offered when there is such a gap.
 */
export function OpeningBalancesPage({
    companyId,
    canImport,
    canReadJournals,
}: {
    companyId: string;
    canImport: boolean;
    canReadJournals: boolean;
}) {
    const { t } = useTranslation();

    useDocumentTitle(t('conversion.pageTitle'));

    const batch = useQuery(openingBatchQuery(companyId));
    const history = useQuery({
        ...historyImportQuery(companyId),
        enabled: Boolean(batch.data),
    });

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('conversion.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('conversion.description')}
                </p>
            </div>

            {batch.isError ? (
                <FormAlert>{genericErrorMessage(batch.error, t)}</FormAlert>
            ) : batch.data === undefined ? (
                <Skeleton className="h-64 w-full max-w-3xl" />
            ) : batch.data === null ? (
                canImport ? (
                    <OpeningBalanceWizard companyId={companyId} />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('conversion.notImported')}
                    </p>
                )
            ) : (
                <div className="flex max-w-3xl flex-col gap-8">
                    <section className="flex flex-col gap-3">
                        <h2 className="text-base font-semibold">
                            {t('conversion.opening.imported')}
                        </h2>
                        <dl className="grid max-w-md grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm">
                            <dt className="text-muted-foreground">
                                {t('conversion.opening.sourceKey')}
                            </dt>
                            <dd>{batch.data.source_key}</dd>
                            {batch.data.source_system && (
                                <>
                                    <dt className="text-muted-foreground">
                                        {t('conversion.opening.sourceSystem')}
                                    </dt>
                                    <dd>{batch.data.source_system}</dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">
                                {t('conversion.opening.cutover')}
                            </dt>
                            <dd>{batch.data.cutover_date}</dd>
                            <dt className="text-muted-foreground">
                                {t('conversion.opening.start')}
                            </dt>
                            <dd>{batch.data.ledger_start_date}</dd>
                            <dt className="text-muted-foreground">
                                {t('conversion.lines')}
                            </dt>
                            <dd>{batch.data.line_count}</dd>
                            <dt className="text-muted-foreground">
                                {t('conversion.journal')}
                            </dt>
                            <dd>
                                {canReadJournals ? (
                                    <Link
                                        to="/w/$companyId/journals/$journalId"
                                        params={{
                                            companyId,
                                            journalId: batch.data.journal_id,
                                        }}
                                        className="underline underline-offset-4"
                                    >
                                        {batch.data.journal_number}
                                    </Link>
                                ) : (
                                    batch.data.journal_number
                                )}
                            </dd>
                        </dl>
                    </section>

                    <section className="flex flex-col gap-3">
                        <h2 className="text-base font-semibold">
                            {t('conversion.history.title')}
                        </h2>
                        {batch.data.cutover_date <=
                        batch.data.ledger_start_date ? (
                            <p className="text-sm text-muted-foreground">
                                {t('conversion.history.none')}
                            </p>
                        ) : history.isError ? (
                            <FormAlert>
                                {genericErrorMessage(history.error, t)}
                            </FormAlert>
                        ) : history.data === undefined ? (
                            <Skeleton className="h-40 w-full" />
                        ) : history.data !== null ? (
                            <dl className="grid max-w-md grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">
                                    {t('conversion.history.sourceKey')}
                                </dt>
                                <dd>{history.data.source_key}</dd>
                                <dt className="text-muted-foreground">
                                    {t('conversion.history.granularityLabel')}
                                </dt>
                                <dd>
                                    {t(
                                        `conversion.history.granularity.${history.data.granularity as 'period_summary' | 'transactions'}`,
                                    )}
                                </dd>
                                <dt className="text-muted-foreground">
                                    {t('conversion.history.journals')}
                                </dt>
                                <dd>{history.data.journal_count}</dd>
                            </dl>
                        ) : canImport ? (
                            <HistoryImportWizard
                                companyId={companyId}
                                batch={batch.data}
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('conversion.history.notImported')}
                            </p>
                        )}
                    </section>
                </div>
            )}
        </>
    );
}
