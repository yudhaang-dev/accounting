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
import { Label } from '@/components/ui/label';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createPostingProfile,
    updatePostingProfile,
    type PostingProfile,
} from '@/modules/subledger/posting-profiles';

type Values = { name: string; is_default: boolean };

function ProfileForm({
    companyId,
    profile,
    onDone,
}: {
    companyId: string;
    profile?: PostingProfile;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            name: profile?.name ?? '',
            is_default: profile?.is_default ?? false,
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;
    // The default cannot be cleared, only moved by making another profile the default.
    const locked = profile?.is_default === true;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'posting-profiles'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            profile
                ? updatePostingProfile(companyId, profile.id, {
                      lock_version: profile.lock_version,
                      name: values.name.trim(),
                      ...(locked ? {} : { is_default: values.is_default }),
                  })
                : createPostingProfile(companyId, {
                      name: values.name.trim(),
                      is_default: values.is_default,
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(
                    profile
                        ? 'postingProfiles.saved'
                        : 'postingProfiles.created',
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
                setFormError(t('postingProfiles.conflict'));

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
                    <FieldLabel htmlFor="profile-name">
                        {t('postingProfiles.name')}
                    </FieldLabel>
                    <Input
                        id="profile-name"
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

                <Field data-invalid={errors.is_default ? true : undefined}>
                    <div className="flex items-center gap-2">
                        <Controller
                            control={control}
                            name="is_default"
                            render={({ field }) => (
                                <Checkbox
                                    id="profile-default"
                                    checked={field.value}
                                    disabled={locked}
                                    onCheckedChange={(checked) =>
                                        field.onChange(checked === true)
                                    }
                                />
                            )}
                        />
                        <Label
                            htmlFor="profile-default"
                            className="font-normal"
                        >
                            {t('postingProfiles.isDefault')}
                        </Label>
                    </div>
                    <FieldDescription>
                        {locked
                            ? t('postingProfiles.defaultLocked')
                            : t('postingProfiles.defaultHint')}
                    </FieldDescription>
                    {errors.is_default && (
                        <FieldError>{errors.is_default.message}</FieldError>
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

/** Adds a posting profile, or renames one and moves the default to it (SL-FR-02). */
export function PostingProfileDialog({
    companyId,
    profile,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The profile being edited; none means a new profile. */
    profile?: PostingProfile;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                profile
                    ? t('postingProfiles.editTitle', { name: profile.name })
                    : t('postingProfiles.createTitle')
            }
            description={t('postingProfiles.description')}
        >
            <ProfileForm
                key={profile?.id ?? 'new'}
                companyId={companyId}
                profile={profile}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
