import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import type { FiscalYear } from '@/modules/general-ledger/calendar';
import {
    planningPolicyQuery,
    updatePlanningPolicy,
    type Plan,
    type PlanningPolicy,
    type PlanVersion,
} from '@/modules/planning/plans';

function PolicyForm({
    companyId,
    policy,
    years,
    plans,
    versions,
    onDone,
}: {
    companyId: string;
    policy: PlanningPolicy;
    years: FiscalYear[];
    plans: Plan[];
    versions: PlanVersion[];
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [distinct, setDistinct] = useState(policy.require_distinct_publisher);
    const [defaults, setDefaults] = useState<Record<string, string>>(
        Object.fromEntries(
            policy.default_versions.map((d) => [
                d.fiscal_year_id,
                d.plan_version_id,
            ]),
        ),
    );
    const [formError, setFormError] = useState<string | null>(null);

    const planName = new Map(plans.map((plan) => [plan.id, plan]));

    /** Only a published version of a plan of the same fiscal year can be the default (PL-FR-04). */
    const itemsFor = (yearId: string) =>
        versions
            .filter(
                (version) =>
                    version.status === 'published' &&
                    planName.get(version.plan_id)?.fiscal_year_id === yearId,
            )
            .map((version) => ({
                value: version.id,
                label: `${planName.get(version.plan_id)?.name ?? ''} · ${version.name}`,
            }));

    const save = useMutation({
        mutationFn: () => {
            const before = new Map(
                policy.default_versions.map((d) => [
                    d.fiscal_year_id,
                    d.plan_version_id,
                ]),
            );

            return updatePlanningPolicy(companyId, {
                lock_version: policy.lock_version,
                require_distinct_publisher: distinct,
                // Years not listed keep their default, so only what changed is sent.
                default_versions: years
                    .filter(
                        (year) =>
                            (defaults[year.id] ?? '') !==
                            (before.get(year.id) ?? ''),
                    )
                    .map((year) => ({
                        fiscal_year_id: year.id,
                        plan_version_id: defaults[year.id] || null,
                    })),
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'plans'],
            });
            toast.success(t('plans.policy.saved'));
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await queryClient.invalidateQueries({
                    queryKey: ['companies', companyId, 'plans', 'policy'],
                });
                setFormError(t('plans.policy.conflict'));

                return;
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                save.mutate();
            }}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field orientation="horizontal">
                    <Checkbox
                        id="policy-distinct"
                        checked={distinct}
                        onCheckedChange={(checked) =>
                            setDistinct(checked === true)
                        }
                    />
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="policy-distinct">
                            {t('plans.policy.distinct')}
                        </Label>
                        <FieldDescription>
                            {t('plans.policy.distinctHint')}
                        </FieldDescription>
                    </div>
                </Field>

                <fieldset className="flex flex-col gap-3">
                    <legend className="mb-1 text-sm font-medium">
                        {t('plans.policy.defaults')}
                    </legend>
                    <p className="text-sm text-muted-foreground">
                        {t('plans.policy.defaultsHint')}
                    </p>
                    {years.map((year) => (
                        <Field key={year.id}>
                            <FieldLabel htmlFor={`policy-default-${year.id}`}>
                                {year.name}
                            </FieldLabel>
                            <SearchableSelect
                                id={`policy-default-${year.id}`}
                                items={itemsFor(year.id)}
                                value={defaults[year.id] ?? ''}
                                onValueChange={(value) =>
                                    setDefaults((current) => ({
                                        ...current,
                                        [year.id]: value,
                                    }))
                                }
                                placeholder={t('plans.policy.noDefault')}
                                emptyText={t('plans.policy.noPublished')}
                                clearable
                            />
                        </Field>
                    ))}
                </fieldset>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('common.save')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

function PolicyLoader({
    companyId,
    years,
    plans,
    versions,
    onDone,
}: {
    companyId: string;
    years: FiscalYear[];
    plans: Plan[];
    versions: PlanVersion[];
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const policy = useQuery(planningPolicyQuery(companyId));

    if (policy.isError) {
        return <FormAlert>{genericErrorMessage(policy.error, t)}</FormAlert>;
    }

    if (policy.data === undefined) {
        return <Skeleton className="h-40 w-full" />;
    }

    return (
        <PolicyForm
            // A conflict reloads the policy; the form restarts from what is stored.
            key={policy.data.lock_version}
            companyId={companyId}
            policy={policy.data}
            years={years}
            plans={plans}
            versions={versions}
            onDone={onDone}
        />
    );
}

/** The publisher rule and the default comparison version of each fiscal year (PL-FR-04). */
export function PlanningPolicyDialog({
    companyId,
    years,
    plans,
    versions,
    open,
    onOpenChange,
}: {
    companyId: string;
    years: FiscalYear[];
    plans: Plan[];
    versions: PlanVersion[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('plans.policy.title')}
            description={t('plans.policy.description')}
        >
            <PolicyLoader
                companyId={companyId}
                years={years}
                plans={plans}
                versions={versions}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
