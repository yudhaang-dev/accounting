import { useQuery } from "@tanstack/react-query";
import { AlertTriangleIcon, CheckIcon } from "lucide-react";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { FormAlert } from "@/components/form-alert";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { useDocumentTitle } from "@/hooks/use-document-title";
import { formatIsoDate, todayIso } from "@/lib/date";
import { formatDecimal } from "@/lib/decimal";
import { genericErrorMessage } from "@/lib/errors";
import { isApiError } from "@/lib/http";
import { ReportFilterBar } from "@/modules/reporting/components/report-filter-bar";
import {
    comparesWithPlan,
    dateModeOf,
    reportDefinitionQuery,
    type ReportDefinition,
    reportResultQuery,
    type ReportResult,
} from "@/modules/reporting/report-definitions";
import {
    emptyFilter,
    type ReportFilter,
} from "@/modules/reporting/report-filter";

const initialFilter = (mode: "range" | "as_of"): ReportFilter => ({
    ...emptyFilter(),
    from: `${new Date().getFullYear()}-01-01`,
    to: todayIso(),
    as_of: mode === "as_of" ? todayIso() : "",
});

/**
 * A report definition run for a range or a date (RP-FR-01, RP-FR-07, RP-FR-08): one value per column,
 * a column the ledger cannot answer for shown as unavailable instead of zero (RP-AC-10), accounts no row
 * claims called out, and the balance sheet and cash flow checks kept in view (RP-AC-01).
 */
export function ReportViewerPage({
    companyId,
    definitionId,
}: {
    companyId: string;
    definitionId: string;
}) {
    const { t } = useTranslation();
    const definition = useQuery(reportDefinitionQuery(companyId, definitionId));

    useDocumentTitle(definition.data?.name ?? t("reports.pageTitle"));

    if (definition.isError) {
        return (
            <FormAlert>{genericErrorMessage(definition.error, t)}</FormAlert>
        );
    }

    if (definition.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    return <Viewer companyId={companyId} definition={definition.data} />;
}

// Mounted once the definition is known, so the filter starts in the date mode of its kind.
function Viewer({
    companyId,
    definition,
}: {
    companyId: string;
    definition: ReportDefinition;
}) {
    const { t, i18n } = useTranslation();
    const mode = dateModeOf(definition);
    const [filter, setFilter] = useState<ReportFilter>(() =>
        initialFilter(mode),
    );
    const result = useQuery(
        reportResultQuery(companyId, definition.id, filter, mode),
    );

    return (
        <>
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
                    {t("reports.versionOf", {
                        version: definition.current_version,
                    })}
                </p>
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
                <ResultView result={result.data} locale={i18n.language} />
            )}
        </>
    );
}

function ResultView({
    result,
    locale,
}: {
    result: ReportResult;
    locale: string;
}) {
    const { t } = useTranslation();
    const money = (value: string | null | undefined) =>
        value === null || value === undefined
            ? "—"
            : formatDecimal(value, locale);

    return (
        <div className="flex flex-col gap-3">
            <div className="text-sm text-muted-foreground">
                {t("reports.currency", {
                    currency: result.functional_currency,
                })}
            </div>

            {result.restricted && (
                <Alert role="status">
                    <AlertTitle>{t("reports.restrictedTitle")}</AlertTitle>
                    <AlertDescription>
                        {t("reports.restricted")}
                    </AlertDescription>
                </Alert>
            )}

            {result.coverage_status !== "complete" && (
                <Alert role="status">
                    <AlertTitle>
                        {t(`reports.coverage.${result.coverage_status}`)}
                    </AlertTitle>
                    <AlertDescription>
                        {result.coverage_from && result.coverage_to
                            ? t("reports.coverage.range", {
                                  from: formatIsoDate(
                                      result.coverage_from,
                                      locale,
                                  ),
                                  to: formatIsoDate(result.coverage_to, locale),
                              })
                            : t("reports.coverage.none")}
                    </AlertDescription>
                </Alert>
            )}

            <Checks result={result} money={money} />

            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t("reports.row")}</TableHead>
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
                                            {t("reports.unavailable")}
                                        </Badge>
                                    )}
                                    {column.available &&
                                        column.coverage_status ===
                                            "partial" && (
                                            <Badge variant="outline">
                                                {t("reports.partial")}
                                            </Badge>
                                        )}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {result.rows.map((row) => (
                            <TableRow
                                key={row.key}
                                data-row-type={row.type}
                                className={
                                    row.type === "unmapped"
                                        ? "bg-muted/50"
                                        : undefined
                                }
                            >
                                <TableCell
                                    className={
                                        row.type === "heading" ||
                                        row.type === "subtotal"
                                            ? "font-semibold"
                                            : undefined
                                    }
                                >
                                    {row.label}
                                </TableCell>
                                {result.columns.map((column) => (
                                    <TableCell
                                        key={column.key}
                                        className={`text-end tabular-nums ${row.type === "subtotal" ? "font-semibold" : ""}`}
                                    >
                                        {row.type === "heading"
                                            ? ""
                                            : money(row.values[column.key])}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {result.unmapped_accounts.length > 0 && (
                <Alert role="status">
                    <AlertTriangleIcon />
                    <AlertTitle>{t("reports.unmappedTitle")}</AlertTitle>
                    <AlertDescription>
                        <p>{t("reports.unmappedDescription")}</p>
                        <ul className="mt-1 list-inside list-disc">
                            {result.unmapped_accounts.map((account) => (
                                <li key={account.account_id}>
                                    <span className="font-mono text-xs">
                                        {account.code}
                                    </span>{" "}
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
                            {t("reports.balanceChecks.balanced")}
                        </Badge>
                        <span className="text-muted-foreground">
                            {label(key)}
                        </span>
                    </div>
                ) : (
                    <Alert key={key} variant="destructive" role="alert">
                        <AlertTitle>
                            {t("reports.balanceChecks.unbalancedTitle", {
                                column: label(key),
                            })}
                        </AlertTitle>
                        <AlertDescription>
                            {t("reports.balanceChecks.unbalanced", {
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
                            {t("reports.cashChecks.reconciled")}
                        </Badge>
                        <span className="text-muted-foreground">
                            {label(key)}
                        </span>
                    </div>
                ) : (
                    <Alert key={key} variant="destructive" role="alert">
                        <AlertTitle>
                            {t("reports.cashChecks.unreconciledTitle", {
                                column: label(key),
                            })}
                        </AlertTitle>
                        <AlertDescription>
                            {t("reports.cashChecks.unreconciled", {
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
