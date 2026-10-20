import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangleIcon } from "lucide-react";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { toast } from "sonner";
import { DatePicker } from "@/components/date-picker";
import { FormAlert } from "@/components/form-alert";
import { SubmitButton } from "@/components/submit-button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { useDocumentTitle } from "@/hooks/use-document-title";
import { formatIsoDate } from "@/lib/date";
import { actionErrorMessage, genericErrorMessage } from "@/lib/errors";
import { isApiError } from "@/lib/http";
import { allAccountsQuery } from "@/modules/general-ledger/accounts";
import {
    cashEquivalentsKey,
    cashEquivalentsQuery,
    saveCashEquivalents,
    type CashEquivalentsMapping,
} from "@/modules/reporting/cash-equivalents";

/**
 * The accounts the cash flow counts as cash and cash equivalents (RP-FR-03, RP-FR-09): the mapping in
 * force, the accounts that no longer qualify, and a new version with the accounts picked. A mapping
 * that is not there makes the cash flow refuse to run, so this screen is where it is repaired.
 */
export function CashEquivalentsPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const mapping = useQuery(cashEquivalentsQuery(companyId));
    const accounts = useQuery(allAccountsQuery(companyId));

    useDocumentTitle(t("cashEquivalents.pageTitle"));

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t("cashEquivalents.title")}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t("cashEquivalents.description")}
                </p>
            </div>

            {mapping.isError || accounts.isError ? (
                <FormAlert>
                    {genericErrorMessage(mapping.error ?? accounts.error, t)}
                </FormAlert>
            ) : mapping.data === undefined || accounts.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : (
                <MappingForm
                    // A new version resets what was picked.
                    key={mapping.data.lock_version}
                    companyId={companyId}
                    mapping={mapping.data}
                    accounts={accounts.data}
                    canManage={canManage}
                />
            )}
        </>
    );
}

function MappingForm({
    companyId,
    mapping,
    accounts,
    canManage,
}: {
    companyId: string;
    mapping: CashEquivalentsMapping;
    accounts: {
        id: string;
        code: string;
        name: string;
        class: string;
        status: string;
        is_leaf: boolean;
    }[];
    canManage: boolean;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [chosen, setChosen] = useState<string[]>(mapping.account_ids);
    const [effective, setEffective] = useState("");
    const [filter, setFilter] = useState("");
    const [formError, setFormError] = useState<string | null>(null);

    const byId = useMemo(
        () => new Map(accounts.map((account) => [account.id, account])),
        [accounts],
    );
    // Only an active asset leaf can be a cash equivalent; an account already mapped stays listed so it can be removed.
    const candidates = accounts
        .filter(
            (account) =>
                (account.class === "asset" &&
                    account.status === "active" &&
                    account.is_leaf) ||
                mapping.account_ids.includes(account.id),
        )
        .filter((account) =>
            `${account.code} ${account.name}`
                .toLowerCase()
                .includes(filter.trim().toLowerCase()),
        );
    const problems = Object.entries(mapping.problems);
    const label = (id: string) => {
        const account = byId.get(id);

        return account ? `${account.code} · ${account.name}` : id;
    };

    const save = useMutation({
        mutationFn: () =>
            saveCashEquivalents(companyId, {
                lock_version: mapping.lock_version,
                account_ids: chosen,
                effective_date: effective || null,
            }),
        onSuccess: async () => {
            setFormError(null);
            await queryClient.invalidateQueries({
                queryKey: cashEquivalentsKey(companyId),
            });
            // The cash flow answers differently once the mapping changed.
            await queryClient.invalidateQueries({
                queryKey: ["companies", companyId, "movement-classifications"],
            });
            toast.success(t("cashEquivalents.saved"));
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === "VERSION_CONFLICT") {
                await queryClient.invalidateQueries({
                    queryKey: cashEquivalentsKey(companyId),
                });
                toast.error(t("cashEquivalents.conflict"));

                return;
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    const changed =
        chosen.length !== mapping.account_ids.length ||
        chosen.some((id) => !mapping.account_ids.includes(id));

    return (
        <div className="flex max-w-2xl flex-col gap-4">
            {mapping.lock_version === 0 ? (
                <Alert role="status">
                    <AlertTriangleIcon />
                    <AlertTitle>{t("cashEquivalents.noneTitle")}</AlertTitle>
                    <AlertDescription>
                        {t("cashEquivalents.none")}
                    </AlertDescription>
                </Alert>
            ) : (
                <p className="text-sm text-muted-foreground">
                    {t("cashEquivalents.current", {
                        version: mapping.lock_version,
                        count: mapping.account_ids.length,
                    })}
                    {mapping.effective_date &&
                        ` ${t("cashEquivalents.since", {
                            date: formatIsoDate(
                                mapping.effective_date,
                                i18n.language,
                            ),
                        })}`}
                </p>
            )}

            {problems.length > 0 && (
                <Alert variant="destructive" role="alert">
                    <AlertTitle>
                        {t("cashEquivalents.problemsTitle")}
                    </AlertTitle>
                    <AlertDescription>
                        <ul className="list-inside list-disc">
                            {problems.map(([id, messages]) => (
                                <li key={id}>
                                    {label(id)}: {messages.join(" ")}
                                </li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}

            <Field>
                <FieldLabel htmlFor="ce-filter">
                    {t("cashEquivalents.accounts")}
                </FieldLabel>
                <Input
                    id="ce-filter"
                    value={filter}
                    placeholder={t("cashEquivalents.filter")}
                    onChange={(event) => setFilter(event.target.value)}
                />
            </Field>
            <div
                role="group"
                aria-label={t("cashEquivalents.accounts")}
                className="flex max-h-80 flex-col gap-2 overflow-y-auto rounded-lg border p-3"
            >
                {candidates.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t("common.noMatch")}
                    </p>
                ) : (
                    candidates.map((account) => (
                        <Field
                            key={account.id}
                            orientation="horizontal"
                            className="w-auto"
                        >
                            <Checkbox
                                id={`ce-${account.id}`}
                                checked={chosen.includes(account.id)}
                                disabled={!canManage}
                                onCheckedChange={(checked) =>
                                    setChosen(
                                        checked === true
                                            ? [...chosen, account.id]
                                            : chosen.filter(
                                                  (id) => id !== account.id,
                                              ),
                                    )
                                }
                            />
                            <FieldLabel
                                htmlFor={`ce-${account.id}`}
                                className="font-normal"
                            >
                                {account.code} · {account.name}
                            </FieldLabel>
                        </Field>
                    ))
                )}
            </div>

            {canManage && (
                <>
                    <Field className="w-56">
                        <FieldLabel
                            id="ce-effective-label"
                            htmlFor="ce-effective"
                        >
                            {t("cashEquivalents.effective")}
                        </FieldLabel>
                        <DatePicker
                            id="ce-effective"
                            labelledBy="ce-effective-label"
                            value={effective}
                            onChange={setEffective}
                            clearable
                        />
                        <FieldDescription>
                            {t("cashEquivalents.effectiveHint")}
                        </FieldDescription>
                    </Field>

                    {formError && <FormAlert>{formError}</FormAlert>}

                    <SubmitButton
                        className="w-auto self-start"
                        pending={save.isPending}
                        pendingLabel={t("common.saving")}
                        disabled={chosen.length === 0 || !changed}
                        onClick={() => save.mutate()}
                    >
                        {t("cashEquivalents.save")}
                    </SubmitButton>
                </>
            )}
        </div>
    );
}
