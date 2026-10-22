import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
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
import { Skeleton } from '@/components/ui/skeleton';
import { CheckItem } from '@/modules/identity-access/components/check-item';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    bankMappingQuery,
    DATE_FORMATS,
    DECIMAL_SEPARATORS,
    DELIMITERS,
    saveBankMapping,
    type BankImportMapping,
    type SaveBankImportMappingInput,
} from '@/modules/subledger/bank-statements';
import type { CashAccount } from '@/modules/subledger/cash';

type Input = SaveBankImportMappingInput;

type Values = {
    delimiter: string;
    date_format: string;
    decimal_separator: string;
    has_header: boolean;
    date_column: string;
    description_column: string;
    reference_column: string;
    amount_mode: 'single' | 'split';
    amount_column: string;
    money_in_column: string;
    money_out_column: string;
};

const text = (value: number | null | undefined) =>
    value === null || value === undefined ? '' : String(value);

/** A column position counted from 1, or null when left empty. */
const position = (value: string): number | null =>
    value.trim() === '' ? null : Number(value);

const validPosition = (value: string): boolean =>
    /^\d+$/.test(value.trim()) && Number(value) >= 1 && Number(value) <= 50;

function defaults(mapping: BankImportMapping | null | undefined): Values {
    return {
        delimiter: mapping?.delimiter ?? ',',
        date_format: mapping?.date_format ?? 'Y-m-d',
        decimal_separator: mapping?.decimal_separator ?? '.',
        has_header: mapping?.has_header ?? true,
        date_column: text(mapping?.date_column ?? 1),
        description_column: text(mapping?.description_column ?? 2),
        reference_column: text(mapping?.reference_column ?? 3),
        amount_mode: mapping?.money_in_column != null ? 'split' : 'single',
        amount_column: text(mapping ? mapping.amount_column : 4),
        money_in_column: text(mapping?.money_in_column),
        money_out_column: text(mapping?.money_out_column),
    };
}

function Choice({
    id,
    label,
    value,
    items,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    items: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Select
                value={value}
                items={items}
                onValueChange={(next) => next !== null && onChange(next)}
            >
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        {items.map((item) => (
                            <SelectItem key={item.value} value={item.value}>
                                {item.label}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
        </Field>
    );
}

function MappingForm({
    companyId,
    cashAccount,
    mapping,
    onDone,
}: {
    companyId: string;
    cashAccount: CashAccount;
    mapping: BankImportMapping | null;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({ defaultValues: defaults(mapping) });
    const {
        control,
        register,
        watch,
        formState: { errors },
    } = form;
    const mode = watch('amount_mode');

    const delimiterItems = DELIMITERS.map((value) => ({
        value,
        label: t(
            `bankStatements.mapping.delimiters.${value === '\t' ? 'tab' : value}`,
        ),
    }));
    const dateItems = DATE_FORMATS.map((value) => ({
        value,
        label: t(`bankStatements.mapping.dateFormats.${value}`),
    }));
    const decimalItems = DECIMAL_SEPARATORS.map((value) => ({
        value,
        label: t(
            `bankStatements.mapping.decimals.${value === '.' ? 'dot' : 'comma'}`,
        ),
    }));
    const modeItems = [
        { value: 'single', label: t('bankStatements.mapping.modeSingle') },
        { value: 'split', label: t('bankStatements.mapping.modeSplit') },
    ];

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'bank-statements', 'mapping'],
        });

    const save = useMutation({
        mutationFn: (values: Values) => {
            const split = values.amount_mode === 'split';

            return saveBankMapping(companyId, cashAccount.id, {
                lock_version: mapping?.lock_version ?? null,
                delimiter: values.delimiter as Input['delimiter'],
                date_format: values.date_format as Input['date_format'],
                decimal_separator:
                    values.decimal_separator as Input['decimal_separator'],
                has_header: values.has_header,
                date_column: Number(values.date_column),
                description_column: position(values.description_column),
                reference_column: position(values.reference_column),
                amount_column: split ? null : position(values.amount_column),
                money_in_column: split
                    ? position(values.money_in_column)
                    : null,
                money_out_column: split
                    ? position(values.money_out_column)
                    : null,
            });
        },
        onSuccess: async () => {
            await refresh();
            toast.success(
                t('bankStatements.mapping.saved', { name: cashAccount.name }),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('bankStatements.mapping.conflict'));

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

    const required = (value: string) =>
        validPosition(value) || t('bankStatements.mapping.columnInvalid');
    const optional = (value: string) =>
        value.trim() === '' ||
        validPosition(value) ||
        t('bankStatements.mapping.columnInvalid');

    const column = (
        name: keyof Values & `${string}_column`,
        label: string,
        rule: (value: string) => true | string,
    ) => (
        <Field data-invalid={errors[name] ? true : undefined}>
            <FieldLabel htmlFor={`bank-map-${name}`}>{label}</FieldLabel>
            <Input
                id={`bank-map-${name}`}
                inputMode="numeric"
                className="w-24"
                aria-invalid={errors[name] ? true : undefined}
                {...register(name, { validate: rule })}
            />
            {errors[name] && <FieldError>{errors[name]?.message}</FieldError>}
        </Field>
    );

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
                <FieldDescription>
                    {t('bankStatements.mapping.hint', {
                        name: cashAccount.name,
                    })}
                </FieldDescription>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Controller
                        control={control}
                        name="delimiter"
                        render={({ field }) => (
                            <Choice
                                id="bank-map-delimiter"
                                label={t('bankStatements.mapping.delimiter')}
                                value={field.value}
                                items={delimiterItems}
                                onChange={field.onChange}
                            />
                        )}
                    />
                    <Controller
                        control={control}
                        name="date_format"
                        render={({ field }) => (
                            <Choice
                                id="bank-map-date-format"
                                label={t('bankStatements.mapping.dateFormat')}
                                value={field.value}
                                items={dateItems}
                                onChange={field.onChange}
                            />
                        )}
                    />
                    <Controller
                        control={control}
                        name="decimal_separator"
                        render={({ field }) => (
                            <Choice
                                id="bank-map-decimal"
                                label={t('bankStatements.mapping.decimal')}
                                value={field.value}
                                items={decimalItems}
                                onChange={field.onChange}
                            />
                        )}
                    />
                </div>

                <Controller
                    control={control}
                    name="has_header"
                    render={({ field }) => (
                        <CheckItem
                            id="bank-map-header"
                            label={t('bankStatements.mapping.hasHeader')}
                            checked={field.value}
                            onChange={field.onChange}
                        />
                    )}
                />

                <div className="flex flex-wrap gap-4">
                    {column(
                        'date_column',
                        t('bankStatements.mapping.dateColumn'),
                        required,
                    )}
                    {column(
                        'description_column',
                        t('bankStatements.mapping.descriptionColumn'),
                        optional,
                    )}
                    {column(
                        'reference_column',
                        t('bankStatements.mapping.referenceColumn'),
                        optional,
                    )}
                </div>

                <Controller
                    control={control}
                    name="amount_mode"
                    render={({ field }) => (
                        <Choice
                            id="bank-map-mode"
                            label={t('bankStatements.mapping.amountMode')}
                            value={field.value}
                            items={modeItems}
                            onChange={(value) =>
                                field.onChange(value as Values['amount_mode'])
                            }
                        />
                    )}
                />
                <div className="flex flex-wrap gap-4">
                    {mode === 'single' ? (
                        column(
                            'amount_column',
                            t('bankStatements.mapping.amountColumn'),
                            required,
                        )
                    ) : (
                        <>
                            {column(
                                'money_in_column',
                                t('bankStatements.mapping.moneyInColumn'),
                                required,
                            )}
                            {column(
                                'money_out_column',
                                t('bankStatements.mapping.moneyOutColumn'),
                                required,
                            )}
                        </>
                    )}
                </div>

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

/**
 * Where the columns of the bank's CSV are for one cash account (SL-FR-13): the delimiter, the date layout and
 * decimal separator, the position of each column, and whether the amount is one signed column or a pair for
 * money in and money out.
 */
export function BankMappingDialog({
    companyId,
    cashAccount,
    onOpenChange,
}: {
    companyId: string;
    /** The account to map; the dialog is closed while this is null. */
    cashAccount: CashAccount | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const mapping = useQuery({
        ...bankMappingQuery(companyId, cashAccount?.id ?? ''),
        enabled: cashAccount !== null,
    });

    return (
        <FormDialog
            open={cashAccount !== null}
            onOpenChange={onOpenChange}
            title={t('bankStatements.mapping.title')}
            description={t('bankStatements.mapping.description')}
            size="lg"
        >
            {cashAccount !== null && mapping.data === undefined ? (
                mapping.isError ? (
                    <FormAlert>
                        {actionErrorMessage(mapping.error, t)}
                    </FormAlert>
                ) : (
                    <Skeleton className="h-48 w-full" />
                )
            ) : (
                cashAccount !== null && (
                    <MappingForm
                        key={`${cashAccount.id}:${mapping.data?.lock_version ?? 'new'}`}
                        companyId={companyId}
                        cashAccount={cashAccount}
                        mapping={mapping.data ?? null}
                        onDone={() => onOpenChange(false)}
                    />
                )
            )}
        </FormDialog>
    );
}
