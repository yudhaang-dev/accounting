import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { AlertTriangleIcon, CheckIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
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
import { todayIso } from '@/lib/date';
import { formatDecimal } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    cashFlowQuery,
    classificationQuery,
    equityChangesQuery,
    type Unclassified,
} from '@/modules/reporting/classifications';
import { CoverageNotice } from '@/modules/reporting/components/coverage-notice';
import { ReportFilterBar } from '@/modules/reporting/components/report-filter-bar';
import {
    emptyFilter,
    type ReportFilter,
} from '@/modules/reporting/report-filter';
import { accountNamesQuery } from '@/modules/transaction-entry/entries';

const initialFilter = (): ReportFilter => ({
    ...emptyFilter(),
    from: `${new Date().getFullYear()}-01-01`,
    to: todayIso(),
});

/** The name of the classification, a filter bar, and whatever the report of it renders. */
function Shell({
    companyId,
    classificationId,
    title,
    description,
    children,
}: {
    companyId: string;
    classificationId: string;
    title: string;
    description: string;
    children: (filter: ReportFilter) => ReactNode;
}) {
    const { t } = useTranslation();
    const [filter, setFilter] = useState<ReportFilter>(initialFilter);
    const classification = useQuery(
        classificationQuery(companyId, classificationId),
    );

    useDocumentTitle(title);

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {classification.data
                        ? t('classifications.of', {
                              name: classification.data.name,
                              version: classification.data.current_version,
                          })
                        : ' '}
                </p>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {description}
                </p>
            </div>
            <ReportFilterBar
                companyId={companyId}
                value={filter}
                onApply={setFilter}
            />
            {children(filter)}
        </>
    );
}

function ErrorView({ error }: { error: unknown }) {
    const { t } = useTranslation();

    return (
        <FormAlert>
            {isApiError(error) && error.status === 422 && error.message
                ? error.message
                : genericErrorMessage(error, t)}
        </FormAlert>
    );
}

/** Accounts a rule does not place in any row, with the key that moved them (RP-FR-09, RP-FR-10). */
function UnclassifiedNotice({
    companyId,
    filter,
    items,
}: {
    companyId: string;
    filter: ReportFilter;
    items: Unclassified[];
}) {
    const { t } = useTranslation();

    if (items.length === 0) {
        return null;
    }

    return (
        <Alert role="status">
            <AlertTriangleIcon />
            <AlertTitle>{t('classifications.unclassifiedTitle')}</AlertTitle>
            <AlertDescription>
                <p>{t('classifications.unclassifiedDescription')}</p>
                <ul className="mt-1 list-inside list-disc">
                    {items.map((item) => (
                        <li
                            key={`${item.account_id}-${item.transaction_type_key}`}
                        >
                            <Link
                                to="/w/$companyId/reports/general-ledger"
                                params={{ companyId }}
                                search={{
                                    account_id: item.account_id,
                                    from: filter.from,
                                    to: filter.to,
                                }}
                                aria-label={t('trialBalance.ledger', {
                                    code: item.code,
                                })}
                                className="font-mono text-xs underline underline-offset-4"
                            >
                                {item.code}
                            </Link>{' '}
                            {item.name}
                            {' · '}
                            {item.transaction_type_key ??
                                t('classifications.noKey')}
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

function Restricted({ restricted }: { restricted: boolean }) {
    const { t } = useTranslation();

    return restricted ? (
        <Alert role="status">
            <AlertTitle>{t('reports.restrictedTitle')}</AlertTitle>
            <AlertDescription>{t('reports.restricted')}</AlertDescription>
        </Alert>
    ) : null;
}

function Reconciled({
    ok,
    okText,
    title,
    children,
}: {
    ok: boolean;
    okText: string;
    title: string;
    children: ReactNode;
}) {
    if (ok) {
        return (
            <Badge variant="secondary" className="self-start">
                <CheckIcon data-icon="inline-start" />
                {okText}
            </Badge>
        );
    }

    return (
        <Alert variant="destructive" role="alert">
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription>{children}</AlertDescription>
        </Alert>
    );
}

/** Direct cash flow (RP-FR-09, RP-AC-02): inflow and outflow per row and the reconciliation to cash. */
export function CashFlowPage({
    companyId,
    classificationId,
    canReadAccounts,
}: {
    companyId: string;
    classificationId: string;
    canReadAccounts: boolean;
}) {
    const { t } = useTranslation();

    return (
        <Shell
            companyId={companyId}
            classificationId={classificationId}
            title={t('classifications.cashFlow.title')}
            description={t('classifications.cashFlow.description')}
        >
            {(filter) => (
                <CashFlowResult
                    companyId={companyId}
                    classificationId={classificationId}
                    filter={filter}
                    canReadAccounts={canReadAccounts}
                />
            )}
        </Shell>
    );
}

function CashFlowResult({
    companyId,
    classificationId,
    filter,
    canReadAccounts,
}: {
    companyId: string;
    classificationId: string;
    filter: ReportFilter;
    canReadAccounts: boolean;
}) {
    const { t, i18n } = useTranslation();
    const report = useQuery(cashFlowQuery(companyId, classificationId, filter));
    const names = useQuery({
        ...accountNamesQuery(companyId),
        enabled: canReadAccounts,
    });
    const money = (value: string) => formatDecimal(value, i18n.language);

    if (report.isError) {
        return (
            <>
                <ErrorView error={report.error} />
                {isApiError(report.error) && report.error.status === 422 && (
                    <Link
                        to="/w/$companyId/reports/cash-equivalents"
                        params={{ companyId }}
                        className="text-sm underline underline-offset-4"
                    >
                        {t('cashEquivalents.fix')}
                    </Link>
                )}
            </>
        );
    }

    if (report.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const data = report.data;
    const problems = Object.entries(data.mapping.problems);

    return (
        <div className="flex flex-col gap-3">
            <div className="text-sm text-muted-foreground">
                {t('reports.currency', { currency: data.functional_currency })}
            </div>
            <Restricted restricted={data.restricted} />
            <CoverageNotice report={data} />

            {problems.length > 0 && (
                <Alert variant="destructive" role="alert">
                    <AlertTitle>
                        {t('classifications.mappingProblemsTitle')}
                    </AlertTitle>
                    <AlertDescription>
                        <Link
                            to="/w/$companyId/reports/cash-equivalents"
                            params={{ companyId }}
                            className="mb-1 block underline underline-offset-4"
                        >
                            {t('cashEquivalents.fix')}
                        </Link>
                        <ul className="list-inside list-disc">
                            {problems.map(([accountId, messages]) => (
                                <li key={accountId}>
                                    {names.data?.[accountId]
                                        ? `${names.data[accountId].code} ${names.data[accountId].name}`
                                        : accountId}
                                    : {messages.join(' ')}
                                </li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}

            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('reports.row')}</TableHead>
                            <TableHead className="text-end">
                                {t('classifications.cashFlow.inflow')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('classifications.cashFlow.outflow')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('classifications.cashFlow.net')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.rows.map((row) => (
                            <TableRow key={row.key}>
                                <TableCell>{row.label}</TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(row.inflow)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(row.outflow)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(row.net)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={3} className="font-medium">
                                {t('classifications.cashFlow.netIncrease')}
                            </TableCell>
                            <TableCell className="text-end font-medium tabular-nums">
                                {money(data.cash.net_increase)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>

            <UnclassifiedNotice
                companyId={companyId}
                filter={filter}
                items={data.unclassified}
            />

            <dl className="grid max-w-md grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm">
                <dt>{t('classifications.cashFlow.opening')}</dt>
                <dd className="text-end tabular-nums">
                    {money(data.cash.opening_balance)}
                </dd>
                <dt>{t('classifications.cashFlow.netIncrease')}</dt>
                <dd className="text-end tabular-nums">
                    {money(data.cash.net_increase)}
                </dd>
                <dt>{t('classifications.cashFlow.closing')}</dt>
                <dd className="text-end tabular-nums">
                    {money(data.cash.closing_balance)}
                </dd>
            </dl>
            <Reconciled
                ok={data.cash.reconciled}
                okText={t('classifications.cashFlow.reconciled')}
                title={t('classifications.cashFlow.unreconciledTitle')}
            >
                {t('classifications.cashFlow.unreconciled', {
                    difference: money(data.cash.difference),
                })}
            </Reconciled>
        </div>
    );
}

/** Changes in equity (RP-FR-10, RP-AC-09): opening plus movement equals closing per component. */
export function EquityChangesPage({
    companyId,
    classificationId,
}: {
    companyId: string;
    classificationId: string;
}) {
    const { t } = useTranslation();

    return (
        <Shell
            companyId={companyId}
            classificationId={classificationId}
            title={t('classifications.equity.title')}
            description={t('classifications.equity.description')}
        >
            {(filter) => (
                <EquityResult
                    companyId={companyId}
                    classificationId={classificationId}
                    filter={filter}
                />
            )}
        </Shell>
    );
}

function EquityResult({
    companyId,
    classificationId,
    filter,
}: {
    companyId: string;
    classificationId: string;
    filter: ReportFilter;
}) {
    const { t, i18n } = useTranslation();
    const report = useQuery(
        equityChangesQuery(companyId, classificationId, filter),
    );
    const money = (value: string | undefined) =>
        formatDecimal(value ?? '0.00', i18n.language);

    if (report.isError) {
        return <ErrorView error={report.error} />;
    }

    if (report.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const data = report.data;

    return (
        <div className="flex flex-col gap-3">
            <div className="text-sm text-muted-foreground">
                {t('reports.currency', { currency: data.functional_currency })}
            </div>
            <Restricted restricted={data.restricted} />
            <CoverageNotice report={data} />

            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>
                                {t('classifications.equity.component')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('classifications.equity.opening')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('classifications.equity.movement')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('classifications.equity.closing')}
                            </TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.components.map((component) => (
                            <TableRow key={component.key}>
                                <TableCell>{component.label}</TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(component.opening_balance)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(component.movement)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(component.closing_balance)}
                                </TableCell>
                                <TableCell>
                                    {!component.reconciled && (
                                        <Badge variant="destructive">
                                            {t(
                                                'classifications.equity.notReconciled',
                                            )}
                                        </Badge>
                                    )}
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
                            <TableHead>{t('reports.row')}</TableHead>
                            {data.components.map((component) => (
                                <TableHead
                                    key={component.key}
                                    className="text-end"
                                >
                                    {component.label}
                                </TableHead>
                            ))}
                            <TableHead className="text-end">
                                {t('classifications.equity.total')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.rows.map((row) => (
                            <TableRow key={row.key}>
                                <TableCell>{row.label}</TableCell>
                                {data.components.map((component) => (
                                    <TableCell
                                        key={component.key}
                                        className="text-end tabular-nums"
                                    >
                                        {money(row.values[component.key])}
                                    </TableCell>
                                ))}
                                <TableCell className="text-end font-medium tabular-nums">
                                    {money(row.total)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <UnclassifiedNotice
                companyId={companyId}
                filter={filter}
                items={data.unclassified}
            />

            <Reconciled
                ok={data.equity.reconciled}
                okText={t('classifications.equity.reconciled')}
                title={t('classifications.equity.unreconciledTitle')}
            >
                {t('classifications.equity.unreconciled', {
                    components: money(data.equity.components_total),
                    ledger: money(data.equity.ledger_total),
                    difference: money(data.equity.difference),
                })}
            </Reconciled>
        </div>
    );
}
