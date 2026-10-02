import { useMutation, useQueryClient } from '@tanstack/react-query';
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
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createRole,
    updateRole,
    type PermissionKey,
    type Role,
} from '@/modules/identity-access/access';
import { PermissionChecklist } from '@/modules/identity-access/components/permission-checklist';

type Values = {
    name: string;
    description: string;
    permissions: string[];
};

function RoleForm({
    companyId,
    role,
    onDone,
}: {
    companyId: string;
    role?: Role;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            name: role?.name ?? '',
            description: role?.description ?? '',
            permissions: role?.permissions ?? [],
        },
    });
    const {
        register,
        control,
        formState: { errors },
    } = form;

    // Changing a role changes what its holders may do, so the member list (which names roles) is stale too.
    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId],
            predicate: (query) =>
                query.queryKey[2] === 'roles' ||
                query.queryKey[2] === 'memberships',
        });

    const save = useMutation({
        mutationFn: (values: Values) => {
            const body = {
                name: values.name.trim(),
                description: values.description.trim() || null,
                permissions: values.permissions as PermissionKey[],
            };

            return role
                ? updateRole(companyId, role.id, {
                      ...body,
                      lock_version: role.lock_version,
                  })
                : createRole(companyId, body);
        },
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(role ? 'roles.form.saved' : 'roles.form.created', {
                    name: saved.name,
                }),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('roles.form.conflict'));

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
                    <FieldLabel htmlFor="role-name">
                        {t('roles.form.name')}
                    </FieldLabel>
                    <Input
                        id="role-name"
                        maxLength={100}
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

                <Field data-invalid={errors.description ? true : undefined}>
                    <FieldLabel htmlFor="role-description">
                        {t('roles.form.descriptionLabel')}
                    </FieldLabel>
                    <Textarea
                        id="role-description"
                        rows={2}
                        {...register('description')}
                    />
                    {errors.description && (
                        <FieldError>{errors.description.message}</FieldError>
                    )}
                </Field>

                <FieldSet data-invalid={errors.permissions ? true : undefined}>
                    <FieldLegend variant="label">
                        {t('roles.form.permissions')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('roles.form.permissionsHint')}
                    </FieldDescription>
                    <Controller
                        control={control}
                        name="permissions"
                        render={({ field }) => (
                            <PermissionChecklist
                                companyId={companyId}
                                idPrefix="role-permission"
                                value={field.value}
                                onChange={field.onChange}
                            />
                        )}
                    />
                    {errors.permissions && (
                        <FieldError>{errors.permissions.message}</FieldError>
                    )}
                </FieldSet>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {role ? t('common.save') : t('roles.form.create')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/**
 * Creates a role or edits one (IA-FR-02): a name and the permissions it grants. A change applies to
 * everyone holding the role at once, without any change in the code (IA-AC-03).
 */
export function RoleDialog({
    companyId,
    role,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The role being edited; none means creating a new one. */
    role?: Role;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title={
                role
                    ? t('roles.form.editTitle', { name: role.name })
                    : t('roles.form.createTitle')
            }
            description={t('roles.description')}
        >
            <RoleForm
                key={role?.id ?? 'new'}
                companyId={companyId}
                role={role}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
