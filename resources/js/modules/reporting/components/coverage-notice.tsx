import { useTranslation } from "react-i18next";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { formatIsoDate } from "@/lib/date";
import type { CoverageStatus } from "@/modules/reporting/report-definitions";

/** What every report says about the history it covers (RP-FR-13). */
export type Coverage = {
    coverage_from: string | null;
    coverage_to: string | null;
    coverage_status: CoverageStatus;
};

/** Tells the reader when the ledger holds only part of the range, or none of it. */
export function CoverageNotice({ report }: { report: Coverage }) {
    const { t, i18n } = useTranslation();

    if (report.coverage_status === "complete") {
        return null;
    }

    return (
        <Alert role="status">
            <AlertTitle>
                {t(`reports.coverage.${report.coverage_status}`)}
            </AlertTitle>
            <AlertDescription>
                {report.coverage_from && report.coverage_to
                    ? t("reports.coverage.range", {
                          from: formatIsoDate(
                              report.coverage_from,
                              i18n.language,
                          ),
                          to: formatIsoDate(report.coverage_to, i18n.language),
                      })
                    : t("reports.coverage.none")}
            </AlertDescription>
        </Alert>
    );
}
