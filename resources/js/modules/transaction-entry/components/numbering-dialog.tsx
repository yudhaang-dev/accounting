import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
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
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLastDefined } from '@/hooks/use-last-defined';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    RESET_PERIODS,
    setNumbering,
    type NumberPolicy,
    type ResetPeriod,
    type TransactionType,
} from '@/modules/transaction-entry/transaction-types';

type Values = {
    pattern: string;
    padding: string;
    reset: ResetPeriod;
    policy: NumberPolicy;
};

const POLICIES: NumberPolicy[] = ['on_create', 'on_submit'];

/** What the pattern produces for the first number of today, so a typo shows before it is saved. */
export function sampleNumber(
    pattern: string,
    padding: number,
    date = new Date(),
): string {
    const pad = (n: number, width = 2) => String(n).padStart(width, '0');

    return pattern
        .replace('{yyyy}', String(date.getFullYear()))
        .replace('{yy}', pad(date.getFullYear() % 100))
        .replace('{mm}', pad(date.getMonth() + 1))
        .replace('{dd}', pad(date.getDate()))
        .replace('{seq}', '1'.padStart(Math.max(1, padding), '0'));
}

function NumberingForm({
    companyId,
    type,
    onDone,
}: {
    companyId: string;
    type: TransactionType;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            pattern: type.numbering.pattern,
            padding: String(type.numbering.padding),
            reset: type.numbering.reset,
            policy: type.numbering.policy,
        },
    });
    const {
        register,
        control,
        formState: { errors },
    } = form;
    const [pattern, padding] = useWatch({
        control,
        name: ['pattern', 'padding'],
    });

    const save = useMutation({
        mutationFn: (values: Values) =>
            setNumbering(companyId, type.id, {
                pattern: values.pattern.trim(),
                padding: Number(values.padding),
                reset: values.reset,
                policy: values.policy,
            }),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'transaction-types'],
            });
            toast.success(t('transactionTypes.numbering.saved'));
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

    const resetItems = RESET_PERIODS.map((value) => ({
        value,
        label: t(`transactionTypes.numbering.resets.${value}`),
    }));
    const policyItems = POLICIES.map((value) => ({
        value,
        label: t(`transactionTypes.numbering.policies.${value}`),
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

                <Field data-invalid={errors.pattern ? true : undefined}>
                    <FieldLabel htmlFor="numbering-pattern">
                        {t('transactionTypes.numbering.pattern')}
                    </FieldLabel>
                    <Input
                        id="numbering-pattern"
                        autoFocus
                        autoCapitalize="none"
                        className="font-mono"
                        aria-invalid={errors.pattern ? true : undefined}
                        {...register('pattern', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.pattern ? (
                        <FieldError>{errors.pattern.message}</FieldError>
                    ) : (
                        <FieldDescription>
                            {t('transactionTypes.numbering.patternHint')}
                        </FieldDescription>
                    )}
                    <p className="text-sm" aria-live="polite">
                        {t('transactionTypes.numbering.sample')}{' '}
                        <span className="font-mono font-medium">
                            {sampleNumber(pattern, Number(padding) || 1)}
                        </span>
                    </p>
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={errors.padding ? true : undefined}>
                        <FieldLabel htmlFor="numbering-padding">
                            {t('transactionTypes.numbering.padding')}
                        </FieldLabel>
                        <Input
                            id="numbering-padding"
                            inputMode="numeric"
                            className="w-24 tabular-nums"
                            aria-invalid={errors.padding ? true : undefined}
                            {...register('padding', {
                                validate: (value) =>
                                    (/^\d{1,2}$/.test(value) &&
                                        Number(value) >= 1 &&
                                        Number(value) <= 18) ||
                                    t('transactionTypes.numbering.paddingHint'),
                            })}
                        />
                        {errors.padding ? (
                            <FieldError>{errors.padding.message}</FieldError>
                        ) : (
                            <FieldDescription>
                                {t('transactionTypes.numbering.paddingHint')}
                            </FieldDescription>
                        )}
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="numbering-reset">
                            {t('transactionTypes.numbering.reset')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="reset"
                            render={({ field }) => (
                                <Select
                                    value={field.value}
                                    items={resetItems}
                                    onValueChange={field.onChange}
                                >
                                    <SelectTrigger
                                        id="numbering-reset"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {resetItems.map((item) => (
                                                <SelectItem
                                                    key={item.value}
                                                    value={item.value}
                                                >
                                                    {item.label}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            )}
                        />
                    </Field>
                </div>

                <Field>
                    <FieldLabel htmlFor="numbering-policy">
                        {t('transactionTypes.numbering.policy')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="policy"
                        render={({ field }) => (
                            <Select
                                value={field.value}
                                items={policyItems}
                                onValueChange={field.onChange}
                            >
                                <SelectTrigger
                                    id="numbering-policy"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {policyItems.map((item) => (
                                            <SelectItem
                                                key={item.value}
                                                value={item.value}
                                            >
                                                {item.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        )}
                    />
                    <FieldDescription>
                        {t('transactionTypes.numbering.policyHint')}
                    </FieldDescription>
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

/** The number series of a transaction type and the moment an entry gets its number (TE-FR-16). */
export function NumberingDialog({
    companyId,
    type,
    onClose,
}: {
    companyId: string;
    /** The type being set up; none keeps the dialog closed. */
    type: TransactionType | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const shown = useLastDefined(type);

    return (
        <FormDialog
            open={type !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={t('transactionTypes.numbering.title', {
                label: shown?.label ?? '',
            })}
            description={t('transactionTypes.numbering.description')}
        >
            {type && (
                <NumberingForm
                    key={type.id}
                    companyId={companyId}
                    type={type}
                    onDone={onClose}
                />
            )}
        </FormDialog>
    );
}
