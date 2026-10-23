import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';
import { Controller, useFieldArray, useForm, useWatch } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { MoneyInput } from '@/components/money-input';
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
import {
    compareDecimals,
    differenceDecimals,
    formatMoney,
    isDecimal,
    sumDecimals,
} from '@/lib/decimal';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    groupAccountsQuery,
    proposeAdjustment,
    rejectAdjustment,
    type Adjustment,
} from '@/modules/consolidation/groups';

type LineValues = { group_account_id: string; debit: string; credit: string };
type ProposeValues = { source: string; reason: string; lines: LineValues[] };

const blankLine = (): LineValues => ({
    group_account_id: '',
    debit: '',
    credit: '',
});

function refresh(
    queryClient: ReturnType<typeof useQueryClient>,
    groupId: string,
    runId: string,
) {
    // Approving changes the result of the run, which is otherwise never refetched.
    return queryClient.invalidateQueries({
        queryKey: ['groups', groupId, 'runs', runId],
    });
}

function ProposeForm({
    groupId,
    runId,
    currency,
    onDone,
}: {
    groupId: string;
    runId: string;
    currency: string;
    onDone: () => void;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const accounts = useQuery(groupAccountsQuery(groupId));
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<ProposeValues>({
        defaultValues: {
            source: '',
            reason: '',
            lines: [blankLine(), blankLine()],
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;
    const { fields, append, remove } = useFieldArray({
        control,
        name: 'lines',
    });
    const lines = useWatch({ control, name: 'lines' });
    const items = (accounts.data ?? [])
        .filter((account) => account.is_active)
        .map((account) => ({
            value: account.id,
            label: `${account.code} · ${account.name}`,
        }));
    const debit = sumDecimals(lines.map((line) => line.debit));
    const credit = sumDecimals(lines.map((line) => line.credit));
    const balanced = compareDecimals(debit, credit) === 0;

    const save = useMutation({
        mutationFn: (values: ProposeValues) =>
            proposeAdjustment(groupId, runId, {
                source: values.source.trim(),
                reason: values.reason.trim(),
                lines: values.lines.map((line) => ({
                    group_account_id: line.group_account_id,
                    debit: line.debit || '0',
                    credit: line.credit || '0',
                })),
            }),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'runs', runId, 'adjustments'],
            });
            toast.success(t('consolidation.adjustments.proposed'));
            onDone();
        },
        onError: (error) => {
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

                <Field data-invalid={errors.source ? true : undefined}>
                    <FieldLabel htmlFor="adjustment-source">
                        {t('consolidation.adjustments.source')}
                    </FieldLabel>
                    <Input
                        id="adjustment-source"
                        autoFocus
                        aria-invalid={errors.source ? true : undefined}
                        {...register('source', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    <FieldDescription>
                        {t('consolidation.adjustments.sourceHint')}
                    </FieldDescription>
                    {errors.source && (
                        <FieldError>{errors.source.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.reason ? true : undefined}>
                    <FieldLabel htmlFor="adjustment-reason">
                        {t('consolidation.adjustments.reason')}
                    </FieldLabel>
                    <Textarea
                        id="adjustment-reason"
                        rows={3}
                        aria-invalid={errors.reason ? true : undefined}
                        {...register('reason', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.reason && (
                        <FieldError>{errors.reason.message}</FieldError>
                    )}
                </Field>

                <div className="flex flex-col gap-3">
                    <div className="text-sm font-medium">
                        {t('consolidation.adjustments.lines', { currency })}
                    </div>
                    {fields.map((field, index) => (
                        <div
                            key={field.id}
                            className="grid grid-cols-[1fr_8rem_8rem_auto] items-start gap-2"
                        >
                            <Controller
                                control={control}
                                name={`lines.${index}.group_account_id`}
                                rules={{
                                    validate: (value) =>
                                        value !== '' || t('common.required'),
                                }}
                                render={({ field: account }) => (
                                    <SearchableSelect
                                        items={items}
                                        value={account.value}
                                        onValueChange={account.onChange}
                                        emptyText={t('common.noMatch')}
                                        aria-label={t(
                                            'consolidation.adjustments.accountN',
                                            { n: index + 1 },
                                        )}
                                        aria-invalid={
                                            errors.lines?.[index]
                                                ?.group_account_id
                                                ? true
                                                : undefined
                                        }
                                    />
                                )}
                            />
                            <Controller
                                control={control}
                                name={`lines.${index}.debit`}
                                render={({ field: amount }) => (
                                    <MoneyInput
                                        maxDecimals={6}
                                        value={amount.value}
                                        onBlur={amount.onBlur}
                                        onChange={amount.onChange}
                                        aria-label={t(
                                            'consolidation.adjustments.debitN',
                                            { n: index + 1 },
                                        )}
                                    />
                                )}
                            />
                            <Controller
                                control={control}
                                name={`lines.${index}.credit`}
                                render={({ field: amount }) => (
                                    <MoneyInput
                                        maxDecimals={6}
                                        value={amount.value}
                                        onBlur={amount.onBlur}
                                        onChange={amount.onChange}
                                        aria-label={t(
                                            'consolidation.adjustments.creditN',
                                            { n: index + 1 },
                                        )}
                                    />
                                )}
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                disabled={fields.length <= 2}
                                aria-label={t(
                                    'consolidation.adjustments.removeN',
                                    { n: index + 1 },
                                )}
                                onClick={() => remove(index)}
                            >
                                <Trash2Icon />
                            </Button>
                        </div>
                    ))}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => append(blankLine())}
                        >
                            <PlusIcon data-icon="inline-start" />
                            {t('consolidation.adjustments.addLine')}
                        </Button>
                        <span
                            className={
                                balanced
                                    ? 'text-sm text-muted-foreground'
                                    : 'text-sm text-destructive'
                            }
                            role="status"
                        >
                            {balanced
                                ? t('consolidation.adjustments.balanced', {
                                      total: formatMoney(debit, i18n.language),
                                  })
                                : t('consolidation.adjustments.unbalanced', {
                                      difference: formatMoney(
                                          differenceDecimals(debit, credit),
                                          i18n.language,
                                      ),
                                  })}
                        </span>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                        disabled={
                            !balanced ||
                            !isDecimal(debit) ||
                            compareDecimals(debit, '0') === 0
                        }
                    >
                        {t('consolidation.adjustments.propose')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Proposes an adjustment of a run on accounts of the group chart; it changes nothing until someone else approves it (CO-FR-08). */
export function ProposeAdjustmentDialog({
    groupId,
    runId,
    currency,
    open,
    onOpenChange,
}: {
    groupId: string;
    runId: string;
    currency: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title={t('consolidation.adjustments.proposeTitle')}
            description={t('consolidation.adjustments.proposeDescription')}
        >
            <ProposeForm
                groupId={groupId}
                runId={runId}
                currency={currency}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}

function RejectForm({
    groupId,
    adjustment,
    onDone,
}: {
    groupId: string;
    adjustment: Adjustment;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<{ reason: string }>({ defaultValues: { reason: '' } });
    const {
        register,
        formState: { errors },
    } = form;

    const reject = useMutation({
        mutationFn: (values: { reason: string }) =>
            rejectAdjustment(groupId, adjustment.id, values.reason.trim()),
        onSuccess: async () => {
            await refresh(queryClient, groupId, adjustment.run_id);
            toast.success(t('consolidation.adjustments.rejected'));
            onDone();
        },
        onError: (error) => setFormError(actionErrorMessage(error, t)),
    });

    return (
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                reject.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}
                <Field data-invalid={errors.reason ? true : undefined}>
                    <FieldLabel htmlFor="adjustment-reject-reason">
                        {t('consolidation.adjustments.rejectReason')}
                    </FieldLabel>
                    <Textarea
                        id="adjustment-reject-reason"
                        rows={3}
                        autoFocus
                        aria-invalid={errors.reason ? true : undefined}
                        {...register('reason', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.reason && (
                        <FieldError>{errors.reason.message}</FieldError>
                    )}
                </Field>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        variant="destructive"
                        pending={reject.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('consolidation.adjustments.reject')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Asks for the reason a pending adjustment is rejected; it stays on record but is not part of the result. */
export function RejectAdjustmentDialog({
    groupId,
    adjustment,
    onOpenChange,
}: {
    groupId: string;
    adjustment: Adjustment | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={adjustment !== null}
            onOpenChange={onOpenChange}
            title={t('consolidation.adjustments.rejectTitle')}
            description={t('consolidation.adjustments.rejectDescription')}
        >
            {adjustment && (
                <RejectForm
                    groupId={groupId}
                    adjustment={adjustment}
                    onDone={() => onOpenChange(false)}
                />
            )}
        </FormDialog>
    );
}
