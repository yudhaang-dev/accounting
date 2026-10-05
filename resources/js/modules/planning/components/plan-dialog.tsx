import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { fiscalYearsQuery } from '@/modules/general-ledger/calendar';
import { createPlan, updatePlan, type Plan } from '@/modules/planning/plans';

type Values = { name: string; fiscal_year_id: string; description: string };

function PlanForm({
    companyId,
    plan,
    onDone,
}: {
    companyId: string;
    plan?: Plan;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const years = useQuery(fiscalYearsQuery(companyId));
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            name: plan?.name ?? '',
            fiscal_year_id: plan?.fiscal_year_id ?? '',
            description: plan?.description ?? '',
        },
    });
    const {
        register,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'plans'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            plan
                ? updatePlan(companyId, plan.id, {
                      lock_version: plan.lock_version,
                      name: values.name.trim(),
                      description: values.description.trim() || null,
                  })
                : createPlan(companyId, {
                      name: values.name.trim(),
                      fiscal_year_id: values.fiscal_year_id,
                      description: values.description.trim() || null,
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(plan ? 'plans.plan.saved' : 'plans.plan.created', {
                    name: saved.name,
                }),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('plans.plan.conflict'));

                return;
            }

            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error)
            ) {
                return;
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    const yearItems = (years.data ?? []).map((year) => ({
        value: year.id,
        label: year.name,
    }));

    return (
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                save.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="plan-name">
                        {t('plans.plan.name')}
                    </FieldLabel>
                    <Input
                        id="plan-name"
                        autoFocus
                        aria-invalid={errors.name ? true : undefined}
                        {...register('name', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.name && (
                        <FieldError>{errors.name.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.fiscal_year_id ? true : undefined}>
                    <FieldLabel htmlFor="plan-year">
                        {t('plans.plan.fiscalYear')}
                    </FieldLabel>
                    <Controller
                        control={form.control}
                        name="fiscal_year_id"
                        rules={{
                            validate: (value) =>
                                Boolean(plan) ||
                                value !== '' ||
                                t('common.required'),
                        }}
                        render={({ field }) => (
                            <SearchableSelect
                                id="plan-year"
                                items={yearItems}
                                value={field.value}
                                onValueChange={field.onChange}
                                disabled={Boolean(plan)}
                                emptyText={t('common.noMatch')}
                                aria-invalid={
                                    errors.fiscal_year_id ? true : undefined
                                }
                            />
                        )}
                    />
                    <FieldDescription>
                        {plan
                            ? t('plans.plan.fiscalYearLocked')
                            : t('plans.plan.fiscalYearHint')}
                    </FieldDescription>
                    {errors.fiscal_year_id && (
                        <FieldError>{errors.fiscal_year_id.message}</FieldError>
                    )}
                </Field>

                <Field>
                    <FieldLabel htmlFor="plan-description">
                        {t('plans.plan.description')}
                    </FieldLabel>
                    <Textarea
                        id="plan-description"
                        rows={3}
                        {...register('description')}
                    />
                </Field>

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

/** Adds a plan for a fiscal year, or edits its name and description (PL-FR-01). The year is fixed once the plan exists. */
export function PlanDialog({
    companyId,
    plan,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The plan being edited; none means a new plan. */
    plan?: Plan;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                plan
                    ? t('plans.plan.editTitle', { name: plan.name })
                    : t('plans.plan.createTitle')
            }
            description={t('plans.description')}
        >
            <PlanForm
                key={plan?.id ?? 'new'}
                companyId={companyId}
                plan={plan}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
