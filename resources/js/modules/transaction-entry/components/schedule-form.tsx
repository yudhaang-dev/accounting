import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import {
    Controller,
    useForm,
    useWatch,
    type UseFormReturn,
} from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { todayIso } from '@/lib/date';
import { isPositive } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    blankLine,
    type Values,
} from '@/modules/transaction-entry/components/entry-values';
import { LinesEditor } from '@/modules/transaction-entry/components/lines-editor';
import { OptionSelect } from '@/modules/transaction-entry/components/option-select';
import { StructuredSection } from '@/modules/transaction-entry/components/structured-section';
import {
    dimensionsBody,
    lineAccountsQuery,
    lineDimensionsQuery,
    type LineAccount,
    type LineDimensionType,
} from '@/modules/transaction-entry/entries';
import {
    allocationValues,
    valuesBody,
    versionSchemaQuery,
} from '@/modules/transaction-entry/field-schema';
import {
    FREQUENCIES,
    createSchedule,
    scheduleQuery,
    updateSchedule,
    type Frequency,
    type Schedule,
} from '@/modules/transaction-entry/recurring-schedules';
import {
    activeVersion,
    transactionTypesQuery,
    usableTypes,
    type TransactionType,
} from '@/modules/transaction-entry/transaction-types';

/**
 * What the form holds. The template is the same shape as an entry's content, so the header fields of an
 * entry stand in for the template's: `accounting_date` is the first date the schedule runs on.
 */
type ScheduleValues = Values & {
    name: string;
    frequency: Frequency;
    interval_count: string;
    end_date: string;
    initial_status: 'draft' | 'submitted';
};

type Template = {
    description?: string;
    document_date?: string | null;
    currency?: string | null;
    exchange_rate?: string | null;
    lines?: {
        account_id: string;
        debit?: string | null;
        credit?: string | null;
        description?: string | null;
        dimensions?: Record<string, string> | null;
    }[];
    values?: Record<string, unknown>;
};

const valuesOf = (typeKey: string, schedule?: Schedule): ScheduleValues => {
    const template = (schedule?.template ?? {}) as Template;

    return {
        name: schedule?.name ?? '',
        frequency: (schedule?.frequency as Frequency | undefined) ?? 'monthly',
        interval_count: String(schedule?.interval_count ?? 1),
        end_date: schedule?.end_date ?? '',
        initial_status:
            (schedule?.initial_status as 'draft' | 'submitted' | undefined) ??
            'draft',
        transaction_type_key: schedule?.transaction_type_key ?? typeKey,
        accounting_date: schedule?.start_date ?? todayIso(),
        document_date: template.document_date ?? '',
        description: template.description ?? '',
        lines: template.lines?.length
            ? template.lines.map((line) => ({
                  account_id: line.account_id,
                  debit: line.debit && isPositive(line.debit) ? line.debit : '',
                  credit:
                      line.credit && isPositive(line.credit) ? line.credit : '',
                  description: line.description ?? '',
                  dimensions: { ...line.dimensions },
              }))
            : [blankLine(), blankLine()],
        values: Object.fromEntries(
            Object.entries(template.values ?? {}).filter(
                (pair): pair is [string, string] => typeof pair[1] === 'string',
            ),
        ),
        allocations: allocationValues(template.values),
        currency: template.currency ?? '',
        exchange_rate: template.exchange_rate ?? '',
        auto_reverse_on: '',
    };
};

function ScheduleFields({
    companyId,
    schedule,
    typeKey: chosenType,
    types,
    accounts,
    dimensionTypes,
}: {
    companyId: string;
    schedule?: Schedule;
    /** The type chosen in the catalog; the form is made for it and the type cannot change here. */
    typeKey?: string;
    types: TransactionType[];
    accounts: LineAccount[];
    dimensionTypes: LineDimensionType[];
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<ScheduleValues>({
        defaultValues: valuesOf(chosenType ?? types[0]?.key ?? '', schedule),
    });
    const {
        register,
        control,
        formState: { errors },
    } = form;
    // The shared pieces read the entry-shaped part of the form; the extra fields are ours alone.
    const entryForm = form as unknown as UseFormReturn<Values>;
    const typeKey = useWatch({ control, name: 'transaction_type_key' });
    const type = types.find((candidate) => candidate.key === typeKey);
    const version =
        type &&
        ((schedule?.transaction_type_version != null &&
            type.versions.find(
                (candidate) =>
                    candidate.version === schedule.transaction_type_version,
            )) ||
            activeVersion(type));
    const structured = version?.mode === 'structured';
    const schema = useQuery({
        ...versionSchemaQuery(companyId, type?.id ?? '', version?.version ?? 0),
        enabled: Boolean(structured && type && version),
    });

    const typeItems = types.map((candidate) => ({
        value: candidate.key,
        label: candidate.label,
    }));
    const frequencyItems = FREQUENCIES.map((value) => ({
        value,
        label: t(`schedules.frequencies.${value}`),
    }));
    const statusItems = (['draft', 'submitted'] as const).map((value) => ({
        value,
        label: t(`schedules.initialStatuses.${value}`),
    }));

    const save = useMutation({
        mutationFn: (values: ScheduleValues) => {
            const template = structured
                ? {
                      description: values.description,
                      document_date: values.document_date || null,
                      currency: values.currency || null,
                      exchange_rate: values.exchange_rate || null,
                      values: valuesBody(
                          schema.data ?? [],
                          values.values,
                          values.allocations,
                      ) as unknown as string[],
                  }
                : {
                      description: values.description,
                      document_date: values.document_date || null,
                      lines: values.lines.map((line) => ({
                          account_id: line.account_id,
                          debit: line.debit || null,
                          credit: line.credit || null,
                          description: line.description || null,
                          dimensions: dimensionsBody(line.dimensions),
                      })),
                  };
            const common = {
                name: values.name.trim(),
                end_date: values.end_date || null,
                initial_status: values.initial_status,
                transaction_type_version: structured
                    ? (version?.version ?? null)
                    : null,
                template,
            };

            return schedule
                ? updateSchedule(companyId, schedule.id, {
                      lock_version: schedule.lock_version,
                      ...common,
                  })
                : createSchedule(companyId, {
                      transaction_type_key: values.transaction_type_key,
                      frequency: values.frequency,
                      interval_count: Number(values.interval_count),
                      start_date: values.accounting_date,
                      ...common,
                  });
        },
        onSuccess: async (saved) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'recurring-schedules'],
            });
            toast.success(t('schedules.saved', { name: saved.name }));
            await navigate({
                to: '/w/$companyId/recurring-schedules',
                params: { companyId },
            });
        },
        onError: async (error) => {
            if (
                isApiError(error) &&
                error.code === 'VERSION_CONFLICT' &&
                schedule
            ) {
                const latest = await queryClient.fetchQuery({
                    ...scheduleQuery(companyId, schedule.id),
                    staleTime: 0,
                });

                form.reset(valuesOf(latest.transaction_type_key, latest));
                setFormError(t('schedules.conflict'));

                return;
            }

            if (isApiError(error) && error.status === 422) {
                // The API names the template's parts `template.…` and the start `start_date`; the form keeps them flat.
                let placed = false;

                for (const [key, messages] of Object.entries(error.errors)) {
                    const message = messages[0];
                    const field = key
                        .replace(/^template\./, '')
                        .replace(/^start_date$/, 'accounting_date');

                    if (message) {
                        form.setError(field as keyof ScheduleValues, {
                            type: 'server',
                            message,
                        });
                        placed = true;
                    }
                }

                if (placed) {
                    return;
                }
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

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

                <FieldSet>
                    <FieldLegend variant="label">
                        {t('schedules.form.when')}
                    </FieldLegend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field data-invalid={errors.name ? true : undefined}>
                            <FieldLabel htmlFor="schedule-name">
                                {t('schedules.name')}
                            </FieldLabel>
                            <Input
                                id="schedule-name"
                                maxLength={120}
                                aria-invalid={errors.name ? true : undefined}
                                {...register('name', {
                                    validate: (value) =>
                                        value.trim() !== '' ||
                                        t('common.required'),
                                })}
                            />
                            {errors.name && (
                                <FieldError>{errors.name.message}</FieldError>
                            )}
                        </Field>
                        <Field>
                            <FieldLabel htmlFor="schedule-type">
                                {t('entries.form.type')}
                            </FieldLabel>
                            <Controller
                                control={control}
                                name="transaction_type_key"
                                render={({ field }) => (
                                    <OptionSelect
                                        id="schedule-type"
                                        value={field.value}
                                        items={typeItems}
                                        disabled={Boolean(
                                            schedule || chosenType,
                                        )}
                                        onValueChange={field.onChange}
                                    />
                                )}
                            />
                            {chosenType && !schedule && (
                                <Link
                                    to="/w/$companyId/recurring-schedules/new"
                                    params={{ companyId }}
                                    className="w-fit text-sm underline-offset-4 hover:underline"
                                >
                                    {t('entries.form.changeType')}
                                </Link>
                            )}
                        </Field>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-4">
                        <Field>
                            <FieldLabel htmlFor="schedule-frequency">
                                {t('schedules.form.frequency')}
                            </FieldLabel>
                            <Controller
                                control={control}
                                name="frequency"
                                render={({ field }) => (
                                    <OptionSelect
                                        id="schedule-frequency"
                                        value={field.value}
                                        items={frequencyItems}
                                        disabled={Boolean(schedule)}
                                        onValueChange={(next) =>
                                            field.onChange(next as Frequency)
                                        }
                                    />
                                )}
                            />
                        </Field>
                        <Field
                            data-invalid={
                                errors.interval_count ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="schedule-interval">
                                {t('schedules.form.interval')}
                            </FieldLabel>
                            <Input
                                id="schedule-interval"
                                inputMode="numeric"
                                disabled={Boolean(schedule)}
                                className="w-24 tabular-nums"
                                aria-invalid={
                                    errors.interval_count ? true : undefined
                                }
                                {...register('interval_count', {
                                    validate: (value) =>
                                        (/^\d{1,3}$/.test(value) &&
                                            Number(value) >= 1 &&
                                            Number(value) <= 366) ||
                                        t('schedules.form.intervalInvalid'),
                                })}
                            />
                            {errors.interval_count && (
                                <FieldError>
                                    {errors.interval_count.message}
                                </FieldError>
                            )}
                        </Field>
                        <Field
                            data-invalid={
                                errors.accounting_date ? true : undefined
                            }
                        >
                            <FieldLabel
                                id="schedule-start-label"
                                htmlFor="schedule-start"
                            >
                                {t('schedules.form.start')}
                            </FieldLabel>
                            <Controller
                                control={control}
                                name="accounting_date"
                                rules={{ required: t('common.required') }}
                                render={({ field }) => (
                                    <DatePicker
                                        id="schedule-start"
                                        labelledBy="schedule-start-label"
                                        value={field.value}
                                        onChange={field.onChange}
                                        disabled={Boolean(schedule)}
                                        aria-invalid={
                                            errors.accounting_date
                                                ? true
                                                : undefined
                                        }
                                    />
                                )}
                            />
                            {errors.accounting_date && (
                                <FieldError>
                                    {errors.accounting_date.message}
                                </FieldError>
                            )}
                        </Field>
                        <Field
                            data-invalid={errors.end_date ? true : undefined}
                        >
                            <FieldLabel
                                id="schedule-end-label"
                                htmlFor="schedule-end"
                            >
                                {t('schedules.form.end')}
                            </FieldLabel>
                            <Controller
                                control={control}
                                name="end_date"
                                render={({ field }) => (
                                    <DatePicker
                                        id="schedule-end"
                                        labelledBy="schedule-end-label"
                                        value={field.value}
                                        onChange={field.onChange}
                                        clearable
                                        aria-invalid={
                                            errors.end_date ? true : undefined
                                        }
                                    />
                                )}
                            />
                            {errors.end_date ? (
                                <FieldError>
                                    {errors.end_date.message}
                                </FieldError>
                            ) : (
                                <FieldDescription>
                                    {t('schedules.form.endHint')}
                                </FieldDescription>
                            )}
                        </Field>
                    </div>
                    <Field className="sm:max-w-xs">
                        <FieldLabel htmlFor="schedule-initial">
                            {t('schedules.form.initialStatus')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="initial_status"
                            render={({ field }) => (
                                <OptionSelect
                                    id="schedule-initial"
                                    value={field.value}
                                    items={statusItems}
                                    onValueChange={(next) =>
                                        field.onChange(
                                            next as 'draft' | 'submitted',
                                        )
                                    }
                                />
                            )}
                        />
                        <FieldDescription>
                            {t('schedules.form.initialStatusHint')}
                        </FieldDescription>
                    </Field>
                </FieldSet>

                <FieldSet>
                    <FieldLegend variant="label">
                        {t('schedules.form.template')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('schedules.form.templateHint')}
                    </FieldDescription>
                    <Field data-invalid={errors.description ? true : undefined}>
                        <FieldLabel htmlFor="schedule-description">
                            {t('entries.form.description')}
                        </FieldLabel>
                        <Textarea
                            id="schedule-description"
                            rows={2}
                            maxLength={500}
                            aria-invalid={errors.description ? true : undefined}
                            {...register('description', {
                                validate: (value) =>
                                    value.trim() !== '' || t('common.required'),
                            })}
                        />
                        {errors.description && (
                            <FieldError>
                                {errors.description.message}
                            </FieldError>
                        )}
                    </Field>

                    {structured && version ? (
                        schema.isError ? (
                            <FormAlert>
                                {genericErrorMessage(schema.error, t)}
                            </FormAlert>
                        ) : schema.data === undefined ? (
                            <Skeleton className="h-40 w-full" />
                        ) : (
                            <StructuredSection
                                companyId={companyId}
                                version={version}
                                fields={schema.data}
                                form={entryForm}
                                accounts={accounts}
                                dimensionTypes={dimensionTypes}
                            />
                        )
                    ) : (
                        <LinesEditor
                            form={entryForm}
                            accounts={accounts}
                            dimensionTypes={dimensionTypes}
                        />
                    )}
                </FieldSet>

                <div className="flex flex-wrap gap-2">
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('common.save')}
                    </SubmitButton>
                    <Button
                        variant="ghost"
                        render={
                            <Link
                                to="/w/$companyId/recurring-schedules"
                                params={{ companyId }}
                            />
                        }
                    >
                        {t('common.cancel')}
                    </Button>
                </div>
            </FieldGroup>
        </form>
    );
}

/**
 * The form of a recurring schedule (TE-FR-14): when it runs, where its entries are left, and the template
 * of what each entry carries. The template holds fixed values only, as an entry does; there are no
 * formulas. When editing, the rhythm and the start stay as they were.
 */
export function ScheduleForm({
    companyId,
    schedule,
    typeKey,
    canUseDimensions,
}: {
    companyId: string;
    schedule?: Schedule;
    /** The type chosen in the catalog for a new schedule. */
    typeKey?: string;
    canUseDimensions: boolean;
}) {
    const { t } = useTranslation();
    const types = useQuery(transactionTypesQuery(companyId));
    const accounts = useQuery(lineAccountsQuery(companyId));
    const dimensions = useQuery({
        ...lineDimensionsQuery(companyId),
        enabled: canUseDimensions,
    });

    if (types.isError || accounts.isError) {
        return (
            <FormAlert>
                {genericErrorMessage(types.error ?? accounts.error, t)}
            </FormAlert>
        );
    }

    if (
        types.data === undefined ||
        accounts.data === undefined ||
        (canUseDimensions && dimensions.isPending)
    ) {
        return <Skeleton className="h-96 w-full" />;
    }

    const usable = types.data.filter(
        (candidate) =>
            usableTypes([candidate]).length > 0 ||
            candidate.key === schedule?.transaction_type_key,
    );

    if (usable.length === 0) {
        return <FormAlert>{t('entries.types.noneUsable')}</FormAlert>;
    }

    if (typeKey !== undefined && !usable.some((type) => type.key === typeKey)) {
        return (
            <div className="flex flex-col items-start gap-3">
                <FormAlert>
                    {t('entries.form.typeUnavailable', { key: typeKey })}
                </FormAlert>
                <Link
                    to="/w/$companyId/recurring-schedules/new"
                    params={{ companyId }}
                    className="text-sm underline-offset-4 hover:underline"
                >
                    {t('entries.form.changeType')}
                </Link>
            </div>
        );
    }

    return (
        <ScheduleFields
            companyId={companyId}
            schedule={schedule}
            typeKey={typeKey}
            types={usable}
            accounts={accounts.data}
            dimensionTypes={dimensions.data ?? []}
        />
    );
}
