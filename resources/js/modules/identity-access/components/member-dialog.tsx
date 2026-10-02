import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
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
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { toIsoDate } from '@/lib/date';
import { isApiError } from '@/lib/http';
import {
    inviteMember,
    updateMembership,
    type Membership,
} from '@/modules/identity-access/access';
import { DataScopeField } from '@/modules/identity-access/components/data-scope-field';
import { RolePicker } from '@/modules/identity-access/components/role-picker';

type Values = {
    email: string;
    /** `YYYY-MM-DD`, or empty for a membership that does not lapse. */
    expires: string;
    role_ids: string[];
    data_scope: string[];
};

/** The member loses access at the end of the chosen day, in the zone of the person choosing it. */
const endOfDay = (date: string): string =>
    new Date(`${date}T23:59:59`).toISOString();

const sameSet = (a: string[], b: string[]): boolean =>
    a.length === b.length && a.every((item) => b.includes(item));

function MemberForm({
    companyId,
    membership,
    canReadDimensions,
    onDone,
}: {
    companyId: string;
    membership?: Membership;
    canReadDimensions: boolean;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const initial: Values = {
        email: '',
        expires: membership?.expires_at
            ? toIsoDate(new Date(membership.expires_at))
            : '',
        role_ids: membership?.roles.map((role) => role.id) ?? [],
        data_scope: membership?.data_scope ?? [],
    };
    const form = useForm<Values>({ defaultValues: initial });
    const {
        register,
        control,
        setError,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId],
            predicate: (query) =>
                query.queryKey[2] === 'memberships' ||
                query.queryKey[2] === 'invitations',
        });

    const save = useMutation({
        mutationFn: async (values: Values): Promise<void> => {
            if (membership) {
                await updateMembership(companyId, membership.id, {
                    lock_version: membership.lock_version,
                    role_ids: values.role_ids,
                    // Only what changed is sent: an unchanged expiry that has since passed would be
                    // refused, and a limited admin may not set a scope at all.
                    ...(values.expires !== initial.expires && {
                        expires_at: values.expires
                            ? endOfDay(values.expires)
                            : null,
                    }),
                    ...(canReadDimensions &&
                        !sameSet(values.data_scope, initial.data_scope) && {
                            data_scope: values.data_scope,
                        }),
                });

                return;
            }

            await inviteMember(companyId, {
                email: values.email.trim(),
                role_ids: values.role_ids,
                ...(values.expires && { expires_at: endOfDay(values.expires) }),
                ...(values.data_scope.length > 0 && {
                    data_scope: values.data_scope,
                }),
            });
        },
        onSuccess: async () => {
            await refresh();
            toast.success(
                membership
                    ? t('members.form.saved')
                    : t('members.form.invited', {
                          email: form.getValues('email').trim(),
                      }),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('members.form.conflict'));

                return;
            }

            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(setError, error, { expires_at: 'expires' })
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

                <Field data-invalid={errors.email ? true : undefined}>
                    <FieldLabel htmlFor="member-email">
                        {t('members.form.email')}
                    </FieldLabel>
                    {membership ? (
                        <Input
                            id="member-email"
                            value={`${membership.user.name} · ${membership.user.email}`}
                            disabled
                            readOnly
                        />
                    ) : (
                        <Input
                            id="member-email"
                            type="email"
                            autoComplete="off"
                            aria-invalid={errors.email ? true : undefined}
                            {...register('email', {
                                required: t('common.required'),
                                pattern: {
                                    value: /^\S+@\S+\.\S+$/,
                                    message: t('members.form.emailInvalid'),
                                },
                            })}
                        />
                    )}
                    {errors.email && (
                        <FieldError>{errors.email.message}</FieldError>
                    )}
                </Field>

                <FieldSet data-invalid={errors.role_ids ? true : undefined}>
                    <FieldLegend variant="label">
                        {t('members.form.roles')}
                    </FieldLegend>
                    <Controller
                        control={control}
                        name="role_ids"
                        rules={{
                            validate: (value) =>
                                value.length > 0 ||
                                t('members.form.rolesRequired'),
                        }}
                        render={({ field }) => (
                            <RolePicker
                                companyId={companyId}
                                value={field.value}
                                onChange={(next) => {
                                    field.onChange(next);
                                }}
                            />
                        )}
                    />
                    {errors.role_ids && (
                        <FieldError>{errors.role_ids.message}</FieldError>
                    )}
                </FieldSet>

                <Field data-invalid={errors.expires ? true : undefined}>
                    <FieldLabel id="member-expires-label">
                        {t('members.form.expires')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="expires"
                        render={({ field }) => (
                            <DatePicker
                                id="member-expires"
                                labelledBy="member-expires-label"
                                value={field.value}
                                onChange={field.onChange}
                                placeholder={t('members.form.noExpiry')}
                                aria-invalid={errors.expires ? true : undefined}
                                clearable
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('members.form.expiresHint')}
                    </FieldDescription>
                    {errors.expires && (
                        <FieldError>{errors.expires.message}</FieldError>
                    )}
                </Field>

                {canReadDimensions && (
                    <FieldSet>
                        <FieldLegend variant="label">
                            {t('members.scope.title')}
                        </FieldLegend>
                        <FieldDescription>
                            {t('members.scope.description')}
                        </FieldDescription>
                        <Controller
                            control={control}
                            name="data_scope"
                            render={({ field }) => (
                                <DataScopeField
                                    companyId={companyId}
                                    value={field.value}
                                    onChange={field.onChange}
                                />
                            )}
                        />
                    </FieldSet>
                )}

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {membership
                            ? t('common.save')
                            : t('members.form.invite')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/**
 * Invites someone with roles, an optional expiry and an optional data scope, or changes the same
 * three on a member (IA-FR-01, IA-FR-03). The e-mail address of a member is not editable.
 */
export function MemberDialog({
    companyId,
    membership,
    canReadDimensions,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The member being edited; none means inviting. */
    membership?: Membership;
    /** Whether the user may list dimension values, which the data scope is picked from. */
    canReadDimensions: boolean;
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
                membership
                    ? t('members.form.editTitle', {
                          name: membership.user.name,
                      })
                    : t('members.form.inviteTitle')
            }
            description={t('members.description')}
        >
            <MemberForm
                key={membership?.id ?? 'new'}
                companyId={companyId}
                membership={membership}
                canReadDimensions={canReadDimensions}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
