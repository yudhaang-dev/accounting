import { PlusIcon, Trash2Icon } from "lucide-react";
import {
    Controller,
    useFieldArray,
    useWatch,
    type Control,
    type FieldValues,
} from "react-hook-form";
import { useTranslation } from "react-i18next";
import { SearchableSelect } from "@/components/searchable-select";
import { Button } from "@/components/ui/button";
import { FieldError } from "@/components/ui/field";
import { ACCOUNT_CLASSES } from "@/modules/general-ledger/accounts";
import { SELECTOR_TYPES } from "@/modules/reporting/definition-form";
import { OptionSelect } from "@/modules/transaction-entry/components/option-select";

export type SelectorLabels = {
    type: (s: number) => string;
    class: (s: number) => string;
    account: (s: number) => string;
    remove: (s: number) => string;
    add: string;
};

/**
 * The list of account selectors of a row, rule or component (RP-FR-02): one account, a parent with
 * everything below it, or a class; never a range of codes. `name` is the path of the array in the form
 * and the prefix of the server's error paths, so a message lands on the choice it names.
 */
export function SelectorList({
    control,
    name,
    errors,
    accountItems,
    labels,
}: {
    control: Control<FieldValues>;
    name: string;
    errors: Record<string, string>;
    accountItems: { value: string; label: string }[];
    labels: SelectorLabels;
}) {
    const selectors = useFieldArray({ control, name });

    return (
        <div className="flex flex-col gap-2">
            {selectors.fields.map((selector, s) => (
                <SelectorRow
                    key={selector.id}
                    control={control}
                    at={`${name}.${s}`}
                    index={s}
                    errors={errors}
                    accountItems={accountItems}
                    labels={labels}
                    onRemove={() => selectors.remove(s)}
                />
            ))}
            {errors[name] && <FieldError>{errors[name]}</FieldError>}
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="self-start"
                onClick={() =>
                    selectors.append({
                        type: "account",
                        id: "",
                        class: "asset",
                    })
                }
            >
                <PlusIcon data-icon="inline-start" />
                {labels.add}
            </Button>
        </div>
    );
}

function SelectorRow({
    control,
    at,
    index,
    errors,
    accountItems,
    labels,
    onRemove,
}: {
    control: Control<FieldValues>;
    at: string;
    index: number;
    errors: Record<string, string>;
    accountItems: { value: string; label: string }[];
    labels: SelectorLabels;
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const s = index + 1;
    const type = useWatch({ control, name: `${at}.type` });
    const message =
        errors[`${at}.id`] ?? errors[`${at}.class`] ?? errors[`${at}.type`];

    return (
        <div className="flex flex-wrap items-start gap-2">
            <Controller
                control={control}
                name={`${at}.type`}
                render={({ field }) => (
                    <OptionSelect
                        value={field.value}
                        onValueChange={field.onChange}
                        className="w-44"
                        aria-label={labels.type(s)}
                        items={SELECTOR_TYPES.map((value) => ({
                            value,
                            label: t(`definitionEditor.selectorTypes.${value}`),
                        }))}
                    />
                )}
            />
            {type === "class" ? (
                <Controller
                    control={control}
                    name={`${at}.class`}
                    render={({ field }) => (
                        <OptionSelect
                            value={field.value}
                            onValueChange={field.onChange}
                            className="w-56"
                            aria-label={labels.class(s)}
                            items={ACCOUNT_CLASSES.map((value) => ({
                                value,
                                label: t(`accountClass.${value}`),
                            }))}
                        />
                    )}
                />
            ) : (
                <Controller
                    control={control}
                    name={`${at}.id`}
                    render={({ field }) => (
                        <SearchableSelect
                            items={accountItems}
                            value={field.value}
                            onValueChange={field.onChange}
                            className="w-72"
                            placeholder={t("definitionEditor.pickAccount")}
                            emptyText={t("common.noMatch")}
                            aria-label={labels.account(s)}
                            aria-invalid={message ? true : undefined}
                        />
                    )}
                />
            )}
            <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                aria-label={labels.remove(s)}
                onClick={onRemove}
            >
                <Trash2Icon />
            </Button>
            {message && (
                <FieldError className="basis-full">{message}</FieldError>
            )}
        </div>
    );
}
