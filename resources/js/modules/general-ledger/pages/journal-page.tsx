import { useQuery, useSuspenseQuery } from '@tanstack/react-query';
import { Link, useRouter } from '@tanstack/react-router';
import { ArrowLeftIcon, LockIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDocumentTitle } from '@/hooks/use-document-title';
import {
    compareDecimals,
    formatDecimal,
    isPositive,
    sumDecimals,
} from '@/lib/decimal';
import {
    accountNamesQuery,
    journalQuery,
} from '@/modules/general-ledger/journals';
import { periodQuery } from '@/modules/general-ledger/periods';

export type JournalAccess = {
    /** Links to the entry the journal came from. */
    entries: boolean;
    /** Names the period instead of showing its id. */
    periods: boolean;
    /** Names the accounts instead of showing their ids. */
    accounts: boolean;
};

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}

/** A posted journal (GL-FR-05, GL-FR-06): header, lines with exact totals, and where it came from. */
export function JournalPage({
    companyId,
    journalId,
    currentUserId,
    access,
}: {
    companyId: string;
    journalId: string;
    currentUserId: number;
    access: JournalAccess;
}) {
    const { t, i18n } = useTranslation();
    const router = useRouter();
    const { data: journal } = useSuspenseQuery(
        journalQuery(companyId, journalId),
    );
    const period = useQuery({
        ...periodQuery(companyId, journal.period_id),
        enabled: access.periods,
    });
    const accounts = useQuery({
        ...accountNamesQuery(companyId),
        enabled: access.accounts,
    });

    useDocumentTitle(t('journals.pageTitle', { number: journal.number }));

    const debit = sumDecimals(journal.lines.map((line) => line.debit));
    const credit = sumDecimals(journal.lines.map((line) => line.credit));
    // The rate column only matters once a line is in another currency than the ledger's.
    const foreign = journal.lines.some(
        (line) => compareDecimals(line.exchange_rate, '1') !== 0,
    );
    const references = Object.entries(journal.references ?? {});
    const postedBy = journal.posted_by;

    return (
        <>
            <div>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => router.history.back()}
                >
                    <ArrowLeftIcon data-icon="inline-start" />
                    {t('journals.back')}
                </Button>
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('journals.title', { number: journal.number })}
                </h1>
                <Badge variant="secondary">
                    {t(`journals.sources.${journal.source}`, {
                        defaultValue: journal.source,
                    })}
                </Badge>
            </div>

            <Alert className="max-w-3xl">
                <LockIcon aria-hidden />
                <AlertDescription>{t('journals.immutable')}</AlertDescription>
            </Alert>

            <Card>
                <CardContent>
                    <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <Fact label={t('journals.accountingDate')}>
                            {journal.accounting_date}
                        </Fact>
                        {journal.document_date && (
                            <Fact label={t('journals.documentDate')}>
                                {journal.document_date}
                            </Fact>
                        )}
                        <Fact label={t('journals.period')}>
                            {period.data?.name ?? journal.period_id}
                        </Fact>
                        {journal.transaction_type_key && (
                            <Fact label={t('journals.type')}>
                                {journal.transaction_type_key}
                            </Fact>
                        )}
                        <Fact label={t('journals.postedAt')}>
                            {new Date(journal.posted_at).toLocaleString(
                                i18n.language,
                            )}
                        </Fact>
                        {postedBy !== null && (
                            <Fact label={t('journals.postedBy')}>
                                {postedBy === currentUserId
                                    ? t('journals.you')
                                    : t('journals.user', { id: postedBy })}
                            </Fact>
                        )}
                        {journal.entry_id && access.entries && (
                            <Fact label={t('journals.entry')}>
                                <Link
                                    to="/w/$companyId/entries/$entryId"
                                    params={{
                                        companyId,
                                        entryId: journal.entry_id,
                                    }}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {t('journals.openEntry')}
                                </Link>
                            </Fact>
                        )}
                        {journal.reverses_journal_id && (
                            <Fact label={t('journals.reverses')}>
                                <Link
                                    to="/w/$companyId/journals/$journalId"
                                    params={{
                                        companyId,
                                        journalId: journal.reverses_journal_id,
                                    }}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {t('journals.openJournal')}
                                </Link>
                            </Fact>
                        )}
                        <div className="flex flex-col gap-0.5 sm:col-span-2 lg:col-span-3">
                            <dt className="text-muted-foreground">
                                {t('journals.description')}
                            </dt>
                            <dd className="font-medium">
                                {journal.description}
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('journals.lines')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-14">
                                        {t('journals.lineNumber')}
                                    </TableHead>
                                    <TableHead>
                                        {t('journals.account')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('journals.debit')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('journals.credit')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {journal.lines.map((line) => {
                                    const account =
                                        accounts.data?.[line.account_id];

                                    return (
                                        <TableRow key={line.line_number}>
                                            <TableCell>
                                                {line.line_number}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span>
                                                        {account
                                                            ? `${account.code} · ${account.name}`
                                                            : accounts.data
                                                              ? t(
                                                                    'journals.unknownAccount',
                                                                )
                                                              : line.account_id}
                                                    </span>
                                                    {line.description && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {line.description}
                                                        </span>
                                                    )}
                                                    {foreign && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {line.currency}{' '}
                                                            {formatDecimal(
                                                                line.transaction_amount,
                                                                i18n.language,
                                                            )}{' '}
                                                            ·{' '}
                                                            {t(
                                                                'journals.rate',
                                                                {
                                                                    rate: line.exchange_rate,
                                                                },
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {isPositive(line.debit)
                                                    ? formatDecimal(
                                                          line.debit,
                                                          i18n.language,
                                                      )
                                                    : ''}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {isPositive(line.credit)
                                                    ? formatDecimal(
                                                          line.credit,
                                                          i18n.language,
                                                      )
                                                    : ''}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell
                                        colSpan={2}
                                        className="font-medium"
                                    >
                                        {t('journals.total')}
                                    </TableCell>
                                    <TableCell className="text-end font-medium tabular-nums">
                                        {formatDecimal(debit, i18n.language)}
                                    </TableCell>
                                    <TableCell className="text-end font-medium tabular-nums">
                                        {formatDecimal(credit, i18n.language)}
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            {references.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('journals.references')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
                            {references.map(([key, value]) => (
                                <div
                                    key={key}
                                    className="grid gap-1 sm:col-span-2 sm:grid-cols-subgrid"
                                >
                                    <dt className="text-muted-foreground">
                                        {key}
                                    </dt>
                                    <dd className="font-medium break-all">
                                        {typeof value === 'string'
                                            ? value
                                            : JSON.stringify(value)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>
            )}
        </>
    );
}
