import { queryOptions } from "@tanstack/react-query";
import { api, unwrap } from "@/api/client";
import type { operations } from "@/api/schema";

type Index =
    operations["report-definition-templates.index"]["responses"][200]["content"]["application/json"];
type Show =
    operations["report-definition-templates.show"]["responses"][200]["content"]["application/json"];

export type TemplateSummary = Index["data"][number];
export type Template = Show["data"];
export type TemplateDefinition = Template["definitions"][number];

/** A selector as a template writes it: a class, or the template code of a parent account. */
export type TemplateSelector = { class?: string; code?: string };

export const templatesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ["companies", companyId, "report-definition-templates"],
        queryFn: async (): Promise<TemplateSummary[]> =>
            (
                await unwrap(
                    api.GET(
                        "/companies/{companyId}/report-definition-templates",
                        { params: { path: { companyId } } },
                    ),
                )
            ).data,
    });

export const templateQuery = (companyId: string, key: string) =>
    queryOptions({
        queryKey: ["companies", companyId, "report-definition-templates", key],
        queryFn: async (): Promise<Template> =>
            (
                await unwrap(
                    api.GET(
                        "/companies/{companyId}/report-definition-templates/{key}",
                        { params: { path: { companyId, key } } },
                    ),
                )
            ).data,
    });

/** Creates the picked definitions after the user confirmed them (X-AC-03); all or none. */
export async function importTemplate(
    companyId: string,
    template: string,
    keys: string[],
) {
    return (
        await unwrap(
            api.POST(
                "/companies/{companyId}/report-definitions/import-template",
                {
                    params: { path: { companyId } },
                    body: { template, keys, confirmed: true } as never,
                },
            ),
        )
    ).data;
}

/** The account codes a definition names that the chart of the company does not have. */
export function missingCodes(
    definition: TemplateDefinition,
    chartCodes: Set<string>,
): string[] {
    const rows = (definition.structure.rows ?? []) as {
        accounts?: TemplateSelector[];
    }[];
    const codes = rows.flatMap((row) =>
        (row.accounts ?? []).flatMap((selector) =>
            selector.code ? [selector.code] : [],
        ),
    );

    return [...new Set(codes)].filter((code) => !chartCodes.has(code));
}
