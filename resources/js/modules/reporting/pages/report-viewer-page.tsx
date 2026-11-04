import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { AlertTriangleIcon, CheckIcon } from 'lucide-react';
import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
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
import { formatIsoDate, todayIso } from '@/lib/date';
import { formatDecimal } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { CoverageNotice } from '@/modules/reporting/components/coverage-notice';
import { ExportButton } from '@/modules/reporting/components/export-button';
import {
    SnapshotDialog,
    type SnapshotTarget,
} from '@/modules/reporting/components/snapshot-dialogs';
import { ReportFilterBar } from '@/modules/reporting/components/report-filter-bar';
import {
    comparesWithPlan,
    dateModeOf,
    exportReportResult,
    reportDefinitionQuery,
    type ReportDefinition,
    reportResultQuery,
    type ReportResult,
} from '@/modules/reporting/report-definitions';
import {
    emptyFilter,
    type ReportFilter,
} from '@/modules/reporting/report-filter';

const initialFilter = (mode: 'range' | 'as_of'): ReportFilter => ({
    ...emptyFilter(),
    from: `${new Date().getFullYear()}-01-01`,
    to: todayIso(),
    as_of: mode === 'as_of' ? todayIso() : '',
});

/**
 * A report definition run for a range or a date (RP-FR-01, RP-FR-07, RP-FR-08): one value per column,
 * a column the ledger cannot answer for shown as unavailable instead of zero (RP-AC-10), accounts no row
 * claims called out, and the balance sheet and cash flow checks kept in view (RP-AC-01).
 */
export function ReportViewerPage({
    companyId,
    definitionId,
    canManage,
    canPublish,
}: {
    companyId: string;
    definitionId: string;
    canManage: boolean;
    canPublish: boolean;
}) {
    const { t } = useTranslation();
    const definition = useQuery(reportDefinitionQuery(companyId, definitionId));

    useDocumentTitle(definition.data?.name ?? t('reports.pageTitle'));

    if (definition.isError) {
        return (
            <FormAlert>{genericErrorMessage(definition.error, t)}</FormAlert>
        );
    }

    if (definition.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    return (
        <Viewer
            companyId={companyId}
            definition={definition.data}
            canManage={canManage}
            canPublish={canPublish}
        />
    );
}

// Mounted once the definition is known, so the filter starts in the date mode of its kind.
function Viewer({
    companyId,
    definition,
    canManage,
    canPublish,
}: {
    companyId: string;
    definition: ReportDefinition;
    canManage: boolean;
    canPublish: boolean;
}) {
    const { t, i18n } = useTranslation();
    const mode = dateModeOf(definition);
    const [filter, setFilter] = useState<ReportFilter>(() =>
        initialFilter(mode),
    );
    const result = useQuery(
        reportResultQuery(companyId, definition.id, filter, mode),
    );
    const [snapshot, setSnapshot] = useState<SnapshotTarget | null>(null);

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {definition.name}
                        </h1>
                        <Badge variant="secondary">
                            {t(`reports.kinds.${definition.kind}`, {
                                defaultValue: definition.kind,
                            })}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {t('reports.versionOf', {
                            version: definition.current_version,
                        })}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <ExportButton
                        disabled={result.data === undefined}
                        onExport={() =>
                            exportReportResult(
                                companyId,
                                definition,
                                filter,
                                mode,
                            )
                        }
                    />
                    <Link
                        to="/w/$companyId/reports/$definitionId/snapshots"
                        params={{ companyId, definitionId: definition.id }}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        {t('snapshots.link')}
                    </Link>
                    {canPublish && (
                        <Button
                            variant="outline"
                            disabled={result.data === undefined}
                            onClick={() =>
                                setSnapshot({
                                    kind: 'publish',
                                    definitionId: definition.id,
                                    filter,
                                    mode,
                                })
                            }
                        >
                            {t('snapshots.publish.button')}
                        </Button>
                    )}
                    {canManage && (
                        <Link
                            to="/w/$companyId/reports/$definitionId/edit"
                            params={{ companyId, definitionId: definition.id }}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            {t('definitionEditor.edit')}
                        </Link>
                    )}
                </div>
            </div>

            <ReportFilterBar
                companyId={companyId}
                value={filter}
                onApply={setFilter}
                dateMode={mode}
                withPlanVersion={comparesWithPlan(definition)}
            />

            {result.isError ? (
                <FormAlert>
                    {isApiError(result.error) &&
                    result.error.status === 422 &&
                    result.error.message
                        ? result.error.message
                        : genericErrorMessage(result.error, t)}
                </FormAlert>
            ) : result.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : (
                <ResultView
                    companyId={companyId}
                    result={result.data}
                    locale={i18n.language}
                />
            )}

            <SnapshotDialog
                companyId={companyId}
                target={snapshot}
                onClose={() => setSnapshot(null)}
            />
        </>
    );
}

/** The ledger of an account over the dates the report covered; a balance sheet reads from the start of its year. */
function ledgerRange(result: ReportResult, accountId: string) {
    const parameters = result.parameters as { from?: string; to?: string };
    const to = parameters.to ?? '';
    const from =
        parameters.from && parameters.from !== to
            ? parameters.from
            : `${to.slice(0, 4)}-01-01`;

    return { account_id: accountId, from, to };
}

/** The ledger of each account a row adds up (X-FR-02): one link for one account, a list for several. */
function AccountLinks({
    companyId,
    result,
    accounts,
}: {
    companyId: string;
    result: ReportResult;
    accounts: ReportResult['rows'][number]['accounts'];
}) {
    const { t } = useTranslation();

    if (!accounts || accounts.length === 0) {
        return null;
    }

    const links = accounts.map((account) => (
        <Link
            key={account.account_id}
            to="/w/$companyId/reports/general-ledger"
            params={{ companyId }}
            search={ledgerRange(result, account.account_id)}
            aria-label={t('trialBalance.ledger', { code: account.code })}
            title={account.name}
            className="font-mono text-xs font-normal underline underline-offset-4"
        >
            {account.code}
        </Link>
    ));

    if (links.length === 1) {
        return <div className="mt-0.5">{links}</div>;
    }

    return (
        <details className="mt-0.5 text-xs font-normal">
            <summary className="cursor-pointer text-muted-foreground">
                {t('reports.ledgerLinks')} ({links.length})
            </summary>
            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1">{links}</div>
        </details>
    );
}

/** The values of a report, as run now or as a snapshot kept them. */
export function ResultView({
    companyId,
    result,
    locale,
}: {
    companyId: string;
    result: ReportResult;
    locale: string;
}) {
    const { t } = useTranslation();
    const money = (value: string | null | undefined) =>
        value === null || value === undefined
            ? '—'
            : formatDecimal(value, locale);

    return (
        <div className="flex flex-col gap-3">
            <div className="text-sm text-muted-foreground">
                {t('reports.currency', {
                    currency: result.functional_currency,
                })}
            </div>

            {result.restricted && (
                <Alert role="status">
                    <AlertTitle>{t('reports.restrictedTitle')}</AlertTitle>
                    <AlertDescription>
                        {t('reports.restricted')}
                    </AlertDescription>
                </Alert>
            )}

            <CoverageNotice report={result} />

            {result.columns
                .filter((column) => column.kind === 'plan' && !column.available)
                .map((column) => (
                    <Alert key={column.key} role="status">
                        <AlertTitle>
                            {t('reports.variance.planUnavailableTitle', {
                                column: column.label,
                            })}
                        </AlertTitle>
                        <AlertDescription>
                            {t('reports.variance.planUnavailable')}
                        </AlertDescription>
                    </Alert>
                ))}

            <Checks result={result} money={money} />

            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('reports.row')}</TableHead>
                            {result.columns.map((column) => (
                                <TableHead
                                    key={column.key}
                                    className="text-end"
                                >
                                    <div>{column.label}</div>
                                    {column.available ? (
                                        column.from && column.to ? (
                                            <div className="text-xs font-normal text-muted-foreground">
                                                {column.from === column.to
                                                    ? formatIsoDate(
                                                          column.to,
                                                          locale,
                                                      )
                                                    : `${formatIsoDate(column.from, locale)} – ${formatIsoDate(column.to, locale)}`}
                                            </div>
                                        ) : null
                                    ) : (
                                        <Badge variant="outline">
                                            {t('reports.unavailable')}
                                        </Badge>
                                    )}
                                    {column.available &&
                                        column.coverage_status ===
                                            'partial' && (
                                            <Badge variant="outline">
                                                {t('reports.partial')}
                                            </Badge>
                                        )}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {result.rows.map((row) => (
                            <Fragment key={row.key}>
                            <TableRow
                                data-row-type={row.type}
                                className={
                                    row.type === 'unmapped'
                                        ? 'bg-muted/50'
                                        : undefined
                                }
                            >
                                <TableCell
                                    className={
                                        row.type === 'heading' ||
                                        row.type === 'subtotal'
                                            ? 'font-semibold'
                                            : undefined
                                    }
                                >
                                    {row.label}
                                    {row.account_lines === undefined && (
                                        <AccountLinks
                                            companyId={companyId}
                                            result={result}
                                            accounts={row.accounts}
                                        />
                                    )}
                                </TableCell>
                                {result.columns.map((column) => (
                                    <TableCell
                                        key={column.key}
                                        className={`text-end tabular-nums ${row.type === 'subtotal' ? 'font-semibold' : ''}`}
                                    >
                                        {row.type === 'heading' ? (
                                            ''
                                        ) : (
                                            <>
                                                {money(row.values[column.key])}
                                                <Variance
                                                    variance={
                                                        row.variances?.[
                                                            column.key
                                                        ]
                                                    }
                                                    locale={locale}
                                                />
                                            </>
                                        )}
                                    </TableCell>
                                ))}
                            </TableRow>
                            {row.account_lines?.map((line) => (
                                <TableRow
                                    key={`${row.key}:${line.account_id}`}
                                    data-account-line={line.account_id}
                                    data-leaf={line.is_leaf}
                                >
                                    <TableCell
                                        className={
                                            line.is_leaf
                                                ? 'text-muted-foreground'
                                                : 'font-medium'
                                        }
                                        style={{
                                            paddingInlineStart: `${1 + (line.level + 1) * 1.25}rem`,
                                        }}
                                    >
                                        {line.name}
                                        {line.is_leaf && (
                                            <Link
                                                to="/w/$companyId/reports/general-ledger"
                                                params={{ companyId }}
                                                search={ledgerRange(
                                                    result,
                                                    line.account_id,
                                                )}
                                                aria-label={t(
                                                    'trialBalance.ledger',
                                                    { code: line.code },
                                                )}
                                                className="ms-2 font-mono text-xs font-normal underline underline-offset-4"
                                            >
                                                {line.code}
                                            </Link>
                                        )}
                                    </TableCell>
                                    {result.columns.map((column) => (
                                        <TableCell
                                            key={column.key}
                                            className={`text-end tabular-nums ${line.is_leaf ? 'text-muted-foreground' : 'font-medium'}`}
                                        >
                                            {money(line.values[column.key])}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                            </Fragment>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {result.unmapped_accounts.length > 0 && (
                <Alert role="status">
                    <AlertTriangleIcon />
                    <AlertTitle>{t('reports.unmappedTitle')}</AlertTitle>
                    <AlertDescription>
                        <p>{t('reports.unmappedDescription')}</p>
                        <ul className="mt-1 list-inside list-disc">
                            {result.unmapped_accounts.map((account) => (
                                <li key={account.account_id}>
                                    <Link
                                        to="/w/$companyId/reports/general-ledger"
                                        params={{ companyId }}
                                        search={ledgerRange(
                                            result,
                                            account.account_id,
                                        )}
                                        aria-label={t('trialBalance.ledger', {
                                            code: account.code,
                                        })}
                                        className="font-mono text-xs underline underline-offset-4"
                                    >
                                        {account.code}
                                    </Link>{' '}
                                    {account.name}
                                </li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}

/**
 * How far a difference column is from its plan (RP-FR-12): the percentage of the absolute plan, and
 * whether the direction is favorable for the row. A zero plan has no percentage, a zero difference no
 * direction.
 */
function Variance({
    variance,
    locale,
}: {
    variance:
        | { percentage: string | null; favorable: boolean | null }
        | undefined;
    locale: string;
}) {
    const { t } = useTranslation();

    if (!variance) {
        return null;
    }

    return (
        <div className="flex items-center justify-end gap-1 text-xs font-normal text-muted-foreground">
            <span>
                {variance.percentage === null
                    ? '—'
                    : `${formatDecimal(variance.percentage, locale)}%`}
            </span>
            {variance.favorable !== null && (
                <Badge
                    variant={variance.favorable ? 'secondary' : 'destructive'}
                >
                    {t(
                        variance.favorable
                            ? 'reports.variance.favorable'
                            : 'reports.variance.unfavorable',
                    )}
                </Badge>
            )}
        </div>
    );
}

/** The balance sheet and cash flow checks: balanced is a quiet badge, a difference is an alert. */
function Checks({
    result,
    money,
}: {
    result: ReportResult;
    money: (value: string | null | undefined) => string;
}) {
    const { t } = useTranslation();
    const label = (key: string) =>
        result.columns.find((column) => column.key === key)?.label ?? key;

    return (
        <>
            {Object.entries(result.balance_checks ?? {}).map(([key, check]) =>
                check.balanced ? (
                    <div key={key} className="flex items-center gap-2 text-sm">
                        <Badge variant="secondary">
                            <CheckIcon data-icon="inline-start" />
                            {t('reports.balanceChecks.balanced')}
                        </Badge>
                        <span className="text-muted-foreground">
                            {label(key)}
                        </span>
                    </div>
                ) : (
                    <Alert key={key} variant="destructive" role="alert">
                        <AlertTitle>
                            {t('reports.balanceChecks.unbalancedTitle', {
                                column: label(key),
                            })}
                        </AlertTitle>
                        <AlertDescription>
                            {t('reports.balanceChecks.unbalanced', {
                                assets: money(check.assets),
                                other: money(check.liabilities_and_equity),
                                difference: money(check.difference),
                            })}
                        </AlertDescription>
                    </Alert>
                ),
            )}
            {Object.entries(result.cash_checks ?? {}).map(([key, check]) =>
                check.reconciled ? (
                    <div key={key} className="flex items-center gap-2 text-sm">
                        <Badge variant="secondary">
                            <CheckIcon data-icon="inline-start" />
                            {t('reports.cashChecks.reconciled')}
                        </Badge>
                        <span className="text-muted-foreground">
                            {label(key)}
                        </span>
                    </div>
                ) : (
                    <Alert key={key} variant="destructive" role="alert">
                        <AlertTitle>
                            {t('reports.cashChecks.unreconciledTitle', {
                                column: label(key),
                            })}
                        </AlertTitle>
                        <AlertDescription>
                            {t('reports.cashChecks.unreconciled', {
                                opening: money(check.opening_balance),
                                increase: money(check.net_increase),
                                closing: money(check.closing_balance),
                            })}
                        </AlertDescription>
                    </Alert>
                ),
            )}
        </>
    );
}
