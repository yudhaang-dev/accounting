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
import { createPlan, updatePlan, type Plan } from '@/modules/planning/plans';
import { lineDimensionsQuery } from '@/modules/transaction-entry/entries';

type Values = {
    name: string;
    objective: string;
    starts_on: string;
    ends_on: string;
    scope_type: string;
    scope_dimension_value_id: string;
    description: string;
};

function PlanForm({
    companyId,
    plan,
    locked,
    onDone,
}: {
    companyId: string;
    plan?: Plan;
    locked: boolean;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const dimensions = useQuery(lineDimensionsQuery(companyId));
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            name: plan?.name ?? '',
            objective: plan?.objective ?? '',
            starts_on: plan?.starts_on ?? '',
            ends_on: plan?.ends_on ?? '',
            scope_type: '',
            scope_dimension_value_id: plan?.scope_dimension_value_id ?? '',
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
        mutationFn: (values: Values) => {
            const common = {
                name: values.name.trim(),
                objective: values.objective.trim() || null,
                description: values.description.trim() || null,
            };
            const fixed = {
                starts_on: values.starts_on,
                ends_on: values.ends_on,
                scope_dimension_value_id:
                    values.scope_dimension_value_id || null,
            };

            // Period and scope are fixed once a version is published (PL-FR-01), so they are not sent then.
            return plan
                ? updatePlan(companyId, plan.id, {
                      lock_version: plan.lock_version,
                      ...common,
                      ...(locked ? {} : fixed),
                  })
                : createPlan(companyId, {
                      ...common,
                      ...fixed,
                  });
        },
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

    const types = dimensions.data ?? [];
    // Editing starts from the type that holds the saved value.
    const savedType =
        types.find((type) =>
            type.values.some(
                (value) => value.id === plan?.scope_dimension_value_id,
            ),
        )?.key ?? '';
    const scopeType = form.watch('scope_type') || savedType;
    const typeItems = types.map((type) => ({
        value: type.key,
        label: type.label,
    }));
    const valueItems = (
        types.find((type) => type.key === scopeType)?.values ?? []
    ).map((value) => ({
        value: value.id,
        label: `${value.code} · ${value.name}`,
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

                <Field>
                    <FieldLabel htmlFor="plan-objective">
                        {t('plans.plan.objective')}
                    </FieldLabel>
                    <Textarea
                        id="plan-objective"
                        rows={2}
                        {...register('objective')}
                    />
                    <FieldDescription>
                        {t('plans.plan.objectiveHint')}
                    </FieldDescription>
                </Field>

                <div className="grid grid-cols-2 gap-3">
                    <Field data-invalid={errors.starts_on ? true : undefined}>
                        <FieldLabel htmlFor="plan-starts-on">
                            {t('plans.plan.startsOn')}
                        </FieldLabel>
                        <Input
                            id="plan-starts-on"
                            type="date"
                            disabled={locked}
                            aria-invalid={errors.starts_on ? true : undefined}
                            {...register('starts_on', {
                                validate: (value) =>
                                    value !== '' || t('common.required'),
                            })}
                        />
                        {errors.starts_on && (
                            <FieldError>{errors.starts_on.message}</FieldError>
                        )}
                    </Field>
                    <Field data-invalid={errors.ends_on ? true : undefined}>
                        <FieldLabel htmlFor="plan-ends-on">
                            {t('plans.plan.endsOn')}
                        </FieldLabel>
                        <Input
                            id="plan-ends-on"
                            type="date"
                            disabled={locked}
                            aria-invalid={errors.ends_on ? true : undefined}
                            {...register('ends_on', {
                                validate: (value) =>
                                    value !== '' || t('common.required'),
                            })}
                        />
                        {errors.ends_on && (
                            <FieldError>{errors.ends_on.message}</FieldError>
                        )}
                    </Field>
                </div>

                <Field
                    data-invalid={
                        errors.scope_dimension_value_id ? true : undefined
                    }
                >
                    <FieldLabel htmlFor="plan-scope-type">
                        {t('plans.plan.scope')}
                    </FieldLabel>
                    <div className="grid grid-cols-2 gap-3">
                        <SearchableSelect
                            id="plan-scope-type"
                            items={typeItems}
                            value={scopeType}
                            onValueChange={(value) => {
                                form.setValue('scope_type', value);
                                form.setValue('scope_dimension_value_id', '');
                            }}
                            placeholder={t('plans.plan.scopeWhole')}
                            clearable
                            disabled={locked}
                            emptyText={t('common.noMatch')}
                            aria-label={t('plans.plan.scopeType')}
                        />
                        <Controller
                            control={form.control}
                            name="scope_dimension_value_id"
                            render={({ field }) => (
                                <SearchableSelect
                                    items={valueItems}
                                    value={field.value}
                                    onValueChange={field.onChange}
                                    disabled={locked || scopeType === ''}
                                    emptyText={t('common.noMatch')}
                                    aria-label={t('plans.plan.scopeValue')}
                                    aria-invalid={
                                        errors.scope_dimension_value_id
                                            ? true
                                            : undefined
                                    }
                                />
                            )}
                        />
                    </div>
                    <FieldDescription>
                        {locked
                            ? t('plans.plan.fixedAfterPublish')
                            : t('plans.plan.scopeHint')}
                    </FieldDescription>
                    {errors.scope_dimension_value_id && (
                        <FieldError>
                            {errors.scope_dimension_value_id.message}
                        </FieldError>
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

/** Adds an activity plan with its own period and optional dimension scope, or edits it (PL-FR-01). Period and scope are fixed once a version is published. */
export function PlanDialog({
    companyId,
    plan,
    locked = false,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The plan being edited; none means a new plan. */
    plan?: Plan;
    /** A version has been published, so the period and the scope can no longer change. */
    locked?: boolean;
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
                locked={locked}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
