import { api, unwrap } from "@/api/client";
import type { MovementClassification } from "@/modules/reporting/classifications";
import type { SelectorRow } from "@/modules/reporting/definition-form";

export type ClassificationRowForm = { key: string; label: string };

export type RuleForm = {
    row: string;
    accounts: SelectorRow[];
    /** Comma-separated Jenis Transaksi keys; empty matches any key. */
    keys: string;
};

export type ComponentForm = {
    key: string;
    label: string;
    accounts: SelectorRow[];
};

export type ClassificationForm = {
    key: string;
    name: string;
    rows: ClassificationRowForm[];
    rules: RuleForm[];
    /** Equity components are optional: only a classification that feeds the changes in equity has them. */
    useComponents: boolean;
    components: ComponentForm[];
};

export const blankSelector = (): SelectorRow => ({
    type: "class",
    id: "",
    class: "asset",
});

export const blankRule = (): RuleForm => ({
    row: "",
    accounts: [blankSelector()],
    keys: "",
});

export const blankComponent = (): ComponentForm => ({
    key: "",
    label: "",
    accounts: [blankSelector()],
});

export const blankClassification = (): ClassificationForm => ({
    key: "",
    name: "",
    rows: [{ key: "", label: "" }],
    rules: [blankRule()],
    useComponents: false,
    components: [],
});

type Structure = {
    rows?: { key: string; label: string }[];
    rules?: {
        row: string;
        accounts: Record<string, string>[];
        transaction_type_keys: string[];
    }[];
    components?: {
        key: string;
        label: string;
        accounts: Record<string, string>[];
    }[];
};

const selectors = (list: Record<string, string>[] | undefined) =>
    (list ?? []).map((selector) => ({
        type: selector.type as SelectorRow["type"],
        id: selector.id ?? "",
        class: selector.class ?? "",
    }));

const selectorBody = (list: SelectorRow[]) =>
    list.map((selector) =>
        selector.type === "class"
            ? { type: "class", class: selector.class }
            : { type: selector.type, id: selector.id },
    );

/** The form of a classification as the API holds it (the generated type leaves out `components`). */
export function toForm(
    classification: MovementClassification,
): ClassificationForm {
    const structure = classification.structure as Structure;

    return {
        key: classification.key,
        name: classification.name,
        rows: (structure.rows ?? []).map(({ key, label }) => ({ key, label })),
        rules: (structure.rules ?? []).map((rule) => ({
            row: rule.row,
            accounts: selectors(rule.accounts),
            keys: (rule.transaction_type_keys ?? []).join(", "),
        })),
        useComponents: structure.components !== undefined,
        components: (structure.components ?? []).map((component) => ({
            key: component.key,
            label: component.label,
            accounts: selectors(component.accounts),
        })),
    };
}

/** What the API takes; components are sent only when the classification uses them. */
export function toStructure(form: ClassificationForm) {
    return {
        rows: form.rows,
        rules: form.rules.map((rule) => ({
            row: rule.row,
            accounts: selectorBody(rule.accounts),
            transaction_type_keys: rule.keys
                .split(",")
                .map((key) => key.trim())
                .filter(Boolean),
        })),
        ...(form.useComponents
            ? {
                  components: form.components.map((component) => ({
                      key: component.key,
                      label: component.label,
                      accounts: selectorBody(component.accounts),
                  })),
              }
            : {}),
    };
}

export async function createClassification(
    companyId: string,
    form: ClassificationForm,
): Promise<MovementClassification> {
    return (
        await unwrap(
            api.POST("/companies/{companyId}/movement-classifications", {
                params: { path: { companyId } },
                body: {
                    key: form.key,
                    name: form.name,
                    structure: toStructure(form),
                } as never,
            }),
        )
    ).data;
}

export async function updateClassification(
    companyId: string,
    classification: MovementClassification,
    form: ClassificationForm,
): Promise<MovementClassification> {
    return (
        await unwrap(
            api.PUT("/companies/{companyId}/movement-classifications/{id}", {
                params: { path: { companyId, id: classification.id } },
                body: {
                    name: form.name,
                    structure: toStructure(form),
                    lock_version: classification.lock_version,
                } as never,
            }),
        )
    ).data;
}
