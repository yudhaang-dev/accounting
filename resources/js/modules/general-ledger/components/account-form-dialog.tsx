import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    ACCOUNT_CLASSES,
    createAccount,
    parentOptionsQuery,
    updateAccount,
    type Account,
    type AccountClass,
} from '@/modules/general-ledger/accounts';

type Values = {
    code: string;
    name: string;
    class: AccountClass;
    /** `default` follows the class; only a new account can choose it. */
    normal_balance: 'default' | 'debit' | 'credit';
    /** Empty means no parent. */
    parent_id: string;
};

const NO_PARENT = '__none';

type Props = {
    companyId: string;
    /** The account being edited; none means a new account. */
    account?: Account;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const valuesOf = (account?: Account): Values => ({
    code: account?.code ?? '',
    name: account?.name ?? '',
    class: (account?.class as AccountClass | undefined) ?? 'asset',
    normal_balance: account
        ? (account.normal_balance as 'debit' | 'credit')
        : 'default',
    parent_id: account?.parent_id ?? '',
});

function AccountForm({
    companyId,
    account,
    onDone,
}: {
    companyId: string;
    account?: Account;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({ defaultValues: valuesOf(account) });
    const {
        register,
        control,
        watch,
        setValue,
        formState: { errors },
    } = form;
    const accountClass = watch('class');
    const parents = useQuery(parentOptionsQuery(companyId, accountClass));

    const refresh = () =>
        Promise.all([
            queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'accounts'],
            }),
            queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'chart-size'],
            }),
        ]);

    const save = useMutation({
        mutationFn: (values: Values) =>
            account
                ? updateAccount(companyId, account.id, {
                      lock_version: account.lock_version,
                      code: values.code,
                      name: values.name,
                      normal_balance:
                          values.normal_balance === 'default'
                              ? undefined
                              : values.normal_balance,
                      parent_id: values.parent_id || null,
                  })
                : createAccount(companyId, {
                      code: values.code,
                      name: values.name,
                      class: values.class,
                      normal_balance:
                          values.normal_balance === 'default'
                              ? null
                              : values.normal_balance,
                      parent_id: values.parent_id || null,
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(account ? 'accounts.form.saved' : 'accounts.form.created', {
                    code: saved.code,
                }),
            );
            onDone();
        },
        onError: async (error) => {
            if (
                isApiError(error) &&
                error.code === 'VERSION_CONFLICT' &&
                account
            ) {
                await refresh();
                setFormError(t('accounts.form.conflict'));

                return;
            }

            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error, { parent_id: 'parent_id' })
            ) {
                return;
            }

            setFormError(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : genericErrorMessage(error, t),
            );
        },
    });

    const parentItems = [
        { value: NO_PARENT, label: t('accounts.form.noParent') },
        ...(parents.data ?? [])
            .filter((candidate) => candidate.id !== account?.id)
            .map((candidate) => ({
                value: candidate.id,
                label: `${candidate.code} · ${candidate.name}`,
            })),
    ];

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

                <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                    <Field data-invalid={errors.code ? true : undefined}>
                        <FieldLabel htmlFor="account-code">
                            {t('accounts.form.code')}
                        </FieldLabel>
                        <Input
                            id="account-code"
                            autoFocus
                            aria-invalid={errors.code ? true : undefined}
                            {...register('code', {
                                required: t('common.required'),
                            })}
                        />
                        {errors.code && (
                            <FieldError>{errors.code.message}</FieldError>
                        )}
                    </Field>
                    <Field data-invalid={errors.name ? true : undefined}>
                        <FieldLabel htmlFor="account-name">
                            {t('accounts.form.name')}
                        </FieldLabel>
                        <Input
                            id="account-name"
                            aria-invalid={errors.name ? true : undefined}
                            {...register('name', {
                                required: t('common.required'),
                            })}
                        />
                        {errors.name && (
                            <FieldError>{errors.name.message}</FieldError>
                        )}
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={errors.class ? true : undefined}>
                        <FieldLabel htmlFor="account-class">
                            {t('accounts.form.class')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="class"
                            render={({ field }) => (
                                <Select
                                    value={field.value}
                                    disabled={Boolean(account)}
                                    items={ACCOUNT_CLASSES.map((value) => ({
                                        value,
                                        label: t(`accountClass.${value}`),
                                    }))}
                                    onValueChange={(value) => {
                                        field.onChange(value);
                                        // A parent must share the class of its children.
                                        setValue('parent_id', '');
                                    }}
                                >
                                    <SelectTrigger
                                        id="account-class"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {ACCOUNT_CLASSES.map((value) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {t(`accountClass.${value}`)}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            )}
                        />
                        {account && (
                            <FieldDescription>
                                {t('accounts.form.classLocked')}
                            </FieldDescription>
                        )}
                    </Field>

                    <Field>
                        <FieldLabel htmlFor="account-normal">
                            {t('accounts.form.normal')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="normal_balance"
                            render={({ field }) => {
                                const options = [
                                    ...(account
                                        ? []
                                        : [
                                              {
                                                  value: 'default',
                                                  label: t(
                                                      'accounts.form.normalDefault',
                                                  ),
                                              },
                                          ]),
                                    {
                                        value: 'debit',
                                        label: t('normalBalance.debit'),
                                    },
                                    {
                                        value: 'credit',
                                        label: t('normalBalance.credit'),
                                    },
                                ];

                                return (
                                    <Select
                                        value={field.value}
                                        items={options}
                                        onValueChange={field.onChange}
                                    >
                                        <SelectTrigger
                                            id="account-normal"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {options.map((option) => (
                                                    <SelectItem
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                );
                            }}
                        />
                        <FieldDescription>
                            {t('accounts.form.normalHint')}
                        </FieldDescription>
                    </Field>
                </div>

                <Field data-invalid={errors.parent_id ? true : undefined}>
                    <FieldLabel htmlFor="account-parent">
                        {t('accounts.form.parent')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="parent_id"
                        render={({ field }) => (
                            <Select
                                value={field.value || NO_PARENT}
                                items={parentItems}
                                onValueChange={(value) =>
                                    field.onChange(
                                        value === NO_PARENT ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="account-parent"
                                    className="w-full"
                                    aria-invalid={
                                        errors.parent_id ? true : undefined
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {parentItems.map((item) => (
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
                    {errors.parent_id && (
                        <FieldError>{errors.parent_id.message}</FieldError>
                    )}
                </Field>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('accounts.form.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('accounts.form.saving')}
                    >
                        {t('accounts.form.save')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Adds an account, or edits one (GL-FR-01). The form is rebuilt each time it opens. */
export function AccountFormDialog({
    companyId,
    account,
    open,
    onOpenChange,
}: Props) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {account
                            ? t('accounts.form.editTitle', {
                                  code: account.code,
                              })
                            : t('accounts.form.createTitle')}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('accounts.description')}
                    </DialogDescription>
                </DialogHeader>
                {open && (
                    <AccountForm
                        key={account?.id ?? 'new'}
                        companyId={companyId}
                        account={account}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
