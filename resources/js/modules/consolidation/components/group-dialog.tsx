import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
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
import { todayIso } from '@/lib/date';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createGroup,
    groupsQuery,
    updateGroup,
    type Group,
    type PackagePolicy,
} from '@/modules/consolidation/groups';
import type { Workspace } from '@/modules/identity-access/workspaces';

type CreateValues = {
    name: string;
    parent_company_id: string;
    presentation_currency: string;
    effective_from: string;
};

function CreateGroupForm({
    companies,
    onCreated,
    onCancel,
}: {
    companies: Workspace[];
    onCreated: (group: { id: string }) => void;
    onCancel: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<CreateValues>({
        defaultValues: {
            name: '',
            parent_company_id:
                companies.length === 1 ? companies[0].company_id : '',
            presentation_currency: '',
            effective_from: todayIso(),
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;
    const items = companies.map((company) => ({
        value: company.company_id,
        label: company.company_name,
    }));

    const create = useMutation({
        mutationFn: (values: CreateValues) =>
            createGroup({
                name: values.name.trim(),
                parent_company_id: values.parent_company_id,
                presentation_currency: values.presentation_currency
                    .trim()
                    .toUpperCase(),
                effective_from: values.effective_from || undefined,
            }),
        onSuccess: async (group) => {
            await queryClient.invalidateQueries({
                queryKey: groupsQuery.queryKey,
            });
            toast.success(
                t('consolidation.group.created', { name: group.name }),
            );
            onCreated(group);
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
                create.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="group-name">
                        {t('consolidation.group.name')}
                    </FieldLabel>
                    <Input
                        id="group-name"
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

                <Field
                    data-invalid={errors.parent_company_id ? true : undefined}
                >
                    <FieldLabel htmlFor="group-parent">
                        {t('consolidation.group.parentCompany')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="parent_company_id"
                        rules={{
                            validate: (value) =>
                                value !== '' || t('common.required'),
                        }}
                        render={({ field }) => (
                            <SearchableSelect
                                id="group-parent"
                                items={items}
                                value={field.value}
                                onValueChange={field.onChange}
                                emptyText={t('common.noMatch')}
                                aria-invalid={
                                    errors.parent_company_id ? true : undefined
                                }
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('consolidation.group.parentHint')}
                    </FieldDescription>
                    {errors.parent_company_id && (
                        <FieldError>
                            {errors.parent_company_id.message}
                        </FieldError>
                    )}
                </Field>

                <Field
                    data-invalid={
                        errors.presentation_currency ? true : undefined
                    }
                >
                    <FieldLabel htmlFor="group-currency">
                        {t('consolidation.group.currency')}
                    </FieldLabel>
                    <Input
                        id="group-currency"
                        maxLength={3}
                        className="w-28 uppercase"
                        aria-invalid={
                            errors.presentation_currency ? true : undefined
                        }
                        {...register('presentation_currency', {
                            validate: (value) =>
                                /^[A-Za-z]{3}$/.test(value.trim()) ||
                                t('consolidation.group.currencyInvalid'),
                        })}
                    />
                    <FieldDescription>
                        {t('consolidation.group.currencyHint')}
                    </FieldDescription>
                    {errors.presentation_currency && (
                        <FieldError>
                            {errors.presentation_currency.message}
                        </FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.effective_from ? true : undefined}>
                    <FieldLabel id="group-effective-label">
                        {t('consolidation.group.effectiveFrom')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="effective_from"
                        render={({ field }) => (
                            <DatePicker
                                labelledBy="group-effective-label"
                                value={field.value}
                                onChange={field.onChange}
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('consolidation.group.effectiveFromHint')}
                    </FieldDescription>
                    {errors.effective_from && (
                        <FieldError>{errors.effective_from.message}</FieldError>
                    )}
                </Field>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onCancel}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        pending={create.isPending}
                        pendingLabel={t('consolidation.group.creating')}
                    >
                        {t('consolidation.group.create')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Creates a group; the companies offered are those the user may put into one (`consolidation.join`). */
export function CreateGroupDialog({
    open,
    onOpenChange,
    companies,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companies: Workspace[];
    onCreated: (group: { id: string }) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('consolidation.group.createTitle')}
            description={t('consolidation.group.createDescription')}
        >
            <CreateGroupForm
                companies={companies}
                onCreated={(group) => {
                    onOpenChange(false);
                    onCreated(group);
                }}
                onCancel={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}

type SettingsValues = { name: string; package_policy: PackagePolicy };

function SettingsForm({ group, onDone }: { group: Group; onDone: () => void }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<SettingsValues>({
        defaultValues: {
            name: group.name,
            package_policy: group.package_policy as PackagePolicy,
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;
    const policies = [
        { value: 'draft', label: t('consolidation.policy.draft') },
        { value: 'reject', label: t('consolidation.policy.reject') },
    ];

    const refresh = () =>
        queryClient.invalidateQueries({ queryKey: groupsQuery.queryKey });

    const save = useMutation({
        mutationFn: (values: SettingsValues) =>
            updateGroup(group.id, {
                lock_version: group.lock_version,
                name: values.name.trim(),
                package_policy: values.package_policy,
            }),
        onSuccess: async () => {
            await refresh();
            toast.success(t('consolidation.settings.saved'));
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

                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="settings-name">
                        {t('consolidation.group.name')}
                    </FieldLabel>
                    <Input
                        id="settings-name"
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
                    <FieldLabel htmlFor="settings-policy">
                        {t('consolidation.settings.policy')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="package_policy"
                        render={({ field }) => (
                            <Select
                                value={field.value}
                                items={policies}
                                onValueChange={(value) =>
                                    field.onChange(value as PackagePolicy)
                                }
                            >
                                <SelectTrigger id="settings-policy">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {policies.map((item) => (
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
                        {t('consolidation.settings.policyHint')}
                    </FieldDescription>
                </Field>

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

/** Renames the group and sets what a run does with packages that are not closed or complete (CO-FR-03). */
export function GroupSettingsDialog({
    group,
    open,
    onOpenChange,
}: {
    group: Group;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('consolidation.settings.title')}
            description={t('consolidation.settings.description')}
        >
            <SettingsForm group={group} onDone={() => onOpenChange(false)} />
        </FormDialog>
    );
}
