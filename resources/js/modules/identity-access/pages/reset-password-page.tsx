import { useMutation } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { AuthLayout } from '@/components/auth-layout';
import { FormAlert } from '@/modules/identity-access/components/form-alert';
import { PasswordField } from '@/modules/identity-access/components/password-field';
import { SubmitButton } from '@/modules/identity-access/components/submit-button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { authApi } from '@/modules/identity-access/auth-api';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';

type Values = {
    email: string;
    password: string;
    password_confirmation: string;
};

const MIN_LENGTH = 8;

export function ResetPasswordPage({
    token,
    email,
}: {
    token: string;
    email?: string;
}) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [formError, setFormError] = useState<string | null>(null);
    const [linkInvalid, setLinkInvalid] = useState(false);

    useDocumentTitle(t('reset.pageTitle'));

    const {
        register,
        handleSubmit,
        setError,
        getValues,
        formState: { errors },
    } = useForm<Values>({
        mode: 'onTouched',
        defaultValues: {
            email: email ?? '',
            password: '',
            password_confirmation: '',
        },
    });

    const reset = useMutation({
        mutationFn: authApi.resetPassword,
        onSuccess: async () => {
            toast.success(t('reset.done'));
            await navigate({ to: '/login', search: {} });
        },
        onError: (error) => {
            if (isApiError(error) && error.status === 422) {
                if (error.first('password')) {
                    setError('password', { message: error.first('password') });

                    return;
                }

                // The only remaining 422 cause is an unusable token or e-mail: the link is dead.
                setLinkInvalid(true);
                setFormError(error.first('email') ?? t('reset.invalidLink'));

                return;
            }

            setFormError(genericErrorMessage(error, t));
        },
    });

    return (
        <AuthLayout
            title={t('reset.title')}
            description={t('reset.description')}
        >
            <form
                noValidate
                onSubmit={handleSubmit((values) => {
                    setFormError(null);
                    reset.mutate({ token, ...values });
                })}
            >
                <FieldGroup>
                    {formError && (
                        <FormAlert>
                            {formError}
                            {linkInvalid && (
                                <>
                                    {' '}
                                    <Link
                                        to="/forgot-password"
                                        search={{
                                            email:
                                                getValues('email') || undefined,
                                        }}
                                        className="font-medium underline underline-offset-4"
                                    >
                                        {t('reset.requestNew')}
                                    </Link>
                                </>
                            )}
                        </FormAlert>
                    )}

                    <Field data-invalid={errors.email ? true : undefined}>
                        <FieldLabel htmlFor="email">
                            {t('common.email')}
                        </FieldLabel>
                        <Input
                            id="email"
                            type="email"
                            autoComplete="username"
                            readOnly={Boolean(email)}
                            aria-invalid={errors.email ? true : undefined}
                            {...register('email', {
                                required: t('common.required'),
                            })}
                        />
                        {errors.email && (
                            <FieldError>{errors.email.message}</FieldError>
                        )}
                    </Field>

                    <PasswordField
                        id="password"
                        label={t('reset.newPassword')}
                        autoComplete="new-password"
                        autoFocus
                        hint={t('reset.hint')}
                        error={errors.password?.message}
                        {...register('password', {
                            required: t('common.required'),
                            minLength: {
                                value: MIN_LENGTH,
                                message: t('reset.hint'),
                            },
                        })}
                    />

                    <PasswordField
                        id="password_confirmation"
                        label={t('reset.confirmPassword')}
                        autoComplete="new-password"
                        error={errors.password_confirmation?.message}
                        {...register('password_confirmation', {
                            required: t('common.required'),
                            // Checked as you leave the field, so a typo is caught before submitting.
                            validate: (value) =>
                                value === getValues('password') ||
                                t('reset.mismatch'),
                        })}
                    />

                    <SubmitButton
                        pending={reset.isPending}
                        pendingLabel={t('reset.submitting')}
                    >
                        {t('reset.submit')}
                    </SubmitButton>
                </FieldGroup>
            </form>
        </AuthLayout>
    );
}
