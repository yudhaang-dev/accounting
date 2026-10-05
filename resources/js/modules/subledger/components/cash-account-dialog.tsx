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
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { allAccountsQuery } from '@/modules/general-ledger/accounts';
import { currenciesQuery } from '@/modules/master-data/currencies';
import {
    cashAccountsQuery,
    createCashAccount,
    eligibleForCash,
    updateCashAccount,
    type CashAccount,
    type CashKind,
} from '@/modules/subledger/cash';

type Values = {
    name: string;
    kind: CashKind;
    bank_name: string;
    account_number: string;
    currency: string;
    account_id: string;
};

function CashAccountForm({
    companyId,
    cashAccount,
    onDone,
}: {
    companyId: string;
    cashAccount?: CashAccount;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const currencies = useQuery(currenciesQuery(companyId));
    const accounts = useQuery(allAccountsQuery(companyId));
    const cashAccounts = useQuery(cashAccountsQuery(companyId));
    const form = useForm<Values>({
        defaultValues: {
            name: cashAccount?.name ?? '',
            kind: (cashAccount?.kind as CashKind | undefined) ?? 'bank',
            bank_name: cashAccount?.bank_name ?? '',
            account_number: cashAccount?.account_number ?? '',
            currency: cashAccount?.currency ?? '',
            account_id: cashAccount?.account_id ?? '',
        },
    });
    const {
        control,
        register,
        watch,
        setValue,
        formState: { errors },
    } = form;
    const kind = watch('kind');
    const currency = watch('currency');
    const editing = cashAccount !== undefined;

    const functional = currencies.data?.find((row) => row.is_functional)?.code;
    const usedAccountIds = new Set(
        (cashAccounts.data ?? []).map((row) => row.account_id),
    );
    const currencyItems = (currencies.data ?? [])
        .filter((row) => row.status === 'active')
        .map((row) => ({
            value: row.code,
            label: `${row.code} · ${row.name}`,
        }));
    const accountItems = (accounts.data ?? [])
        .filter(
            (account) =>
                currency !== '' &&
                functional !== undefined &&
                !usedAccountIds.has(account.id) &&
                eligibleForCash(account, currency, functional),
        )
        .map((account) => ({
            value: account.id,
            label: `${account.code} · ${account.name}`,
        }));
    const kindItems = [
        { value: 'bank', label: t('cashAccounts.kind.bank') },
        { value: 'cash', label: t('cashAccounts.kind.cash') },
    ];
    const ledgerAccount = (accounts.data ?? []).find(
        (account) => account.id === cashAccount?.account_id,
    );

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'cash-accounts'],
        });

    const save = useMutation({
        mutationFn: (values: Values) => {
            const bank = values.kind === 'bank';
            const details = {
                bank_name: bank ? values.bank_name.trim() || null : null,
                account_number: bank
                    ? values.account_number.trim() || null
                    : null,
            };

            return cashAccount
                ? updateCashAccount(companyId, cashAccount.id, {
                      lock_version: cashAccount.lock_version,
                      name: values.name.trim(),
                      ...details,
                  })
                : createCashAccount(companyId, {
                      name: values.name.trim(),
                      kind: values.kind,
                      currency: values.currency,
                      account_id: values.account_id,
                      ...details,
                  });
        },
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(cashAccount ? 'cashAccounts.saved' : 'cashAccounts.created', {
                    name: saved.name,
                }),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('cashAccounts.conflict'));

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
                    <FieldLabel htmlFor="cash-name">
                        {t('cashAccounts.name')}
                    </FieldLabel>
                    <Input
                        id="cash-name"
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
                    <FieldLabel htmlFor="cash-kind">
                        {t('cashAccounts.kindLabel')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="kind"
                        render={({ field }) => (
                            <Select
                                value={field.value}
                                items={kindItems}
                                disabled={editing}
                                onValueChange={(value) =>
                                    field.onChange(value as CashKind)
                                }
                            >
                                <SelectTrigger id="cash-kind">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {kindItems.map((item) => (
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

                {kind === 'bank' && (
                    <>
                        <Field
                            data-invalid={errors.bank_name ? true : undefined}
                        >
                            <FieldLabel htmlFor="cash-bank-name">
                                {t('cashAccounts.bankName')}
                            </FieldLabel>
                            <Input
                                id="cash-bank-name"
                                aria-invalid={
                                    errors.bank_name ? true : undefined
                                }
                                {...register('bank_name', {
                                    validate: (value) =>
                                        value.trim() !== '' ||
                                        t('common.required'),
                                })}
                            />
                            {errors.bank_name && (
                                <FieldError>
                                    {errors.bank_name.message}
                                </FieldError>
                            )}
                        </Field>
                        <Field
                            data-invalid={
                                errors.account_number ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="cash-account-number">
                                {t('cashAccounts.accountNumber')}
                            </FieldLabel>
                            <Input
                                id="cash-account-number"
                                aria-invalid={
                                    errors.account_number ? true : undefined
                                }
                                {...register('account_number', {
                                    validate: (value) =>
                                        value.trim() !== '' ||
                                        t('common.required'),
                                })}
                            />
                            {errors.account_number && (
                                <FieldError>
                                    {errors.account_number.message}
                                </FieldError>
                            )}
                        </Field>
                    </>
                )}

                <Field data-invalid={errors.currency ? true : undefined}>
                    <FieldLabel htmlFor="cash-currency">
                        {t('cashAccounts.currency')}
                    </FieldLabel>
                    {editing ? (
                        <Input
                            id="cash-currency"
                            value={cashAccount.currency}
                            disabled
                            readOnly
                        />
                    ) : (
                        <Controller
                            control={control}
                            name="currency"
                            rules={{ required: t('common.required') }}
                            render={({ field }) => (
                                <SearchableSelect
                                    id="cash-currency"
                                    items={currencyItems}
                                    value={field.value}
                                    onValueChange={(value) => {
                                        field.onChange(value);
                                        setValue('account_id', '');
                                    }}
                                    aria-invalid={
                                        errors.currency ? true : undefined
                                    }
                                    placeholder={t(
                                        'cashAccounts.chooseCurrency',
                                    )}
                                    emptyText={t('common.noMatch')}
                                />
                            )}
                        />
                    )}
                    {errors.currency && (
                        <FieldError>{errors.currency.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.account_id ? true : undefined}>
                    <FieldLabel htmlFor="cash-ledger-account">
                        {t('cashAccounts.ledgerAccount')}
                    </FieldLabel>
                    {editing ? (
                        <Input
                            id="cash-ledger-account"
                            value={
                                ledgerAccount
                                    ? `${ledgerAccount.code} · ${ledgerAccount.name}`
                                    : cashAccount.account_id
                            }
                            disabled
                            readOnly
                        />
                    ) : (
                        <Controller
                            control={control}
                            name="account_id"
                            rules={{ required: t('common.required') }}
                            render={({ field }) => (
                                <SearchableSelect
                                    id="cash-ledger-account"
                                    items={accountItems}
                                    value={field.value}
                                    onValueChange={field.onChange}
                                    aria-invalid={
                                        errors.account_id ? true : undefined
                                    }
                                    placeholder={t(
                                        'cashAccounts.chooseAccount',
                                    )}
                                    emptyText={t('common.noMatch')}
                                />
                            )}
                        />
                    )}
                    <FieldDescription>
                        {editing
                            ? t('cashAccounts.fixedHint')
                            : t('cashAccounts.accountHint')}
                    </FieldDescription>
                    {errors.account_id && (
                        <FieldError>{errors.account_id.message}</FieldError>
                    )}
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

/** Adds a cash box or bank account, or changes the name and bank details of one (SL-FR-03). */
export function CashAccountDialog({
    companyId,
    cashAccount,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The account being edited; none means a new one. */
    cashAccount?: CashAccount;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                cashAccount
                    ? t('cashAccounts.editTitle', { name: cashAccount.name })
                    : t('cashAccounts.createTitle')
            }
            description={t('cashAccounts.description')}
        >
            <CashAccountForm
                key={cashAccount?.id ?? 'new'}
                companyId={companyId}
                cashAccount={cashAccount}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
