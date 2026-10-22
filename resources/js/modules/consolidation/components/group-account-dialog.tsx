import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    createGroupAccount,
    GROUP_ACCOUNT_CLASSES,
    updateGroupAccount,
    type GroupAccount,
    type GroupAccountClass,
} from '@/modules/consolidation/groups';

type Values = {
    code: string;
    name: string;
    class: GroupAccountClass;
    is_active: boolean;
};

function AccountForm({
    groupId,
    account,
    onDone,
}: {
    groupId: string;
    account?: GroupAccount;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const editing = account !== undefined;
    const form = useForm<Values>({
        defaultValues: {
            code: account?.code ?? '',
            name: account?.name ?? '',
            class: (account?.class as GroupAccountClass | undefined) ?? 'asset',
            is_active: account?.is_active ?? true,
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;
    const classes = GROUP_ACCOUNT_CLASSES.map((value) => ({
        value,
        label: t(`consolidation.classes.${value}`),
    }));

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['groups', groupId, 'accounts'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            account
                ? updateGroupAccount(groupId, account.id, {
                      lock_version: account.lock_version,
                      name: values.name.trim(),
                      is_active: values.is_active,
                  })
                : createGroupAccount(groupId, {
                      code: values.code.trim(),
                      name: values.name.trim(),
                      class: values.class,
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(
                    editing
                        ? 'consolidation.accounts.saved'
                        : 'consolidation.accounts.created',
                    {
                        name: saved.name,
                    },
                ),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('consolidation.conflict'));

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

                <Field data-invalid={errors.code ? true : undefined}>
                    <FieldLabel htmlFor="group-account-code">
                        {t('consolidation.accounts.code')}
                    </FieldLabel>
                    <Input
                        id="group-account-code"
                        autoFocus={!editing}
                        disabled={editing}
                        aria-invalid={errors.code ? true : undefined}
                        {...register('code', {
                            validate: (value) =>
                                editing ||
                                value.trim() !== '' ||
                                t('common.required'),
                        })}
                    />
                    {errors.code && (
                        <FieldError>{errors.code.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="group-account-name">
                        {t('consolidation.accounts.name')}
                    </FieldLabel>
                    <Input
                        id="group-account-name"
                        autoFocus={editing}
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

                <Field data-invalid={errors.class ? true : undefined}>
                    <FieldLabel htmlFor="group-account-class">
                        {t('consolidation.accounts.class')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="class"
                        render={({ field }) => (
                            <Select
                                value={field.value}
                                items={classes}
                                disabled={editing}
                                onValueChange={(value) =>
                                    field.onChange(value as GroupAccountClass)
                                }
                            >
                                <SelectTrigger id="group-account-class">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {classes.map((item) => (
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
                        {t('consolidation.accounts.classHint')}
                    </FieldDescription>
                    {errors.class && (
                        <FieldError>{errors.class.message}</FieldError>
                    )}
                </Field>

                {editing && (
                    <Field orientation="horizontal">
                        <Controller
                            control={control}
                            name="is_active"
                            render={({ field }) => (
                                <Checkbox
                                    id="group-account-active"
                                    checked={field.value}
                                    onCheckedChange={(next) =>
                                        field.onChange(next === true)
                                    }
                                />
                            )}
                        />
                        <FieldLabel
                            htmlFor="group-account-active"
                            className="font-normal"
                        >
                            {t('consolidation.accounts.active')}
                        </FieldLabel>
                    </Field>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
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

/** Creates an account of the group chart, or renames or deactivates one; its code and class stay as they were. */
export function GroupAccountDialog({
    groupId,
    open,
    account,
    onOpenChange,
}: {
    groupId: string;
    open: boolean;
    /** The account to change; none to create one. */
    account?: GroupAccount;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t(
                account
                    ? 'consolidation.accounts.editTitle'
                    : 'consolidation.accounts.newTitle',
            )}
            description={t('consolidation.accounts.dialogDescription')}
        >
            <AccountForm
                groupId={groupId}
                account={account}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
