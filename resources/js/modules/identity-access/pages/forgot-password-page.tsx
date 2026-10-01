import { useMutation } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { MailCheckIcon } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { AuthLayout } from '@/components/auth-layout';
import { FormAlert } from '@/modules/identity-access/components/form-alert';
import { SubmitButton } from '@/modules/identity-access/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useCountdown } from '@/hooks/use-countdown';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { authApi } from '@/modules/identity-access/auth-api';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';

const RESEND_COOLDOWN_SECONDS = 60;

export function ForgotPasswordPage({
    email: initialEmail,
}: {
    email?: string;
}) {
    const { t } = useTranslation();
    const cooldown = useCountdown();
    const [sentTo, setSentTo] = useState<string | null>(null);
    const [formError, setFormError] = useState<string | null>(null);

    useDocumentTitle(t('forgot.pageTitle'));

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors },
    } = useForm<{ email: string }>({
        defaultValues: { email: initialEmail ?? '' },
    });

    const send = useMutation({
        mutationFn: authApi.forgotPassword,
        onError: (error) => {
            if (isApiError(error) && error.isRateLimited) {
                cooldown.start(error.retryAfter ?? RESEND_COOLDOWN_SECONDS);
                setFormError(t('login.rateLimited'));
            } else if (
                isApiError(error) &&
                error.status === 422 &&
                error.first('email')
            ) {
                setError('email', { message: error.first('email') });
            } else {
                setFormError(genericErrorMessage(error, t));
            }
        },
    });

    const submit = (email: string, resend = false) => {
        setFormError(null);
        send.mutate(email, {
            onSuccess: () => {
                setSentTo(email);
                cooldown.start(RESEND_COOLDOWN_SECONDS);

                if (resend) {
                    toast.success(t('forgot.resent'));
                }
            },
        });
    };

    if (sentTo) {
        return (
            <AuthLayout
                title={t('forgot.sentTitle')}
                description={t('forgot.sentDescription', { email: sentTo })}
                footer={
                    <Link
                        to="/login"
                        search={{}}
                        className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        {t('common.backToLogin')}
                    </Link>
                }
            >
                <FieldGroup>
                    <div
                        className="flex justify-center text-primary motion-safe:animate-in motion-safe:duration-300 motion-safe:zoom-in-50"
                        aria-hidden
                    >
                        <MailCheckIcon className="size-10" />
                    </div>

                    {formError && <FormAlert>{formError}</FormAlert>}

                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        disabled={cooldown.active || send.isPending}
                        onClick={() => submit(sentTo, true)}
                    >
                        {cooldown.active
                            ? t('forgot.resendIn', {
                                  seconds: cooldown.remaining,
                              })
                            : t('forgot.resend')}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        className="w-full text-muted-foreground"
                        onClick={() => setSentTo(null)}
                    >
                        {t('forgot.useAnotherEmail')}
                    </Button>
                </FieldGroup>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title={t('forgot.title')}
            description={t('forgot.description')}
            footer={
                <Link
                    to="/login"
                    search={{}}
                    className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    {t('common.backToLogin')}
                </Link>
            }
        >
            <form
                noValidate
                onSubmit={handleSubmit(({ email }) => submit(email))}
            >
                <FieldGroup>
                    {formError && <FormAlert>{formError}</FormAlert>}

                    <Field data-invalid={errors.email ? true : undefined}>
                        <FieldLabel htmlFor="email">
                            {t('common.email')}
                        </FieldLabel>
                        <Input
                            id="email"
                            type="email"
                            inputMode="email"
                            autoComplete="email"
                            autoFocus
                            aria-invalid={errors.email ? true : undefined}
                            {...register('email', {
                                required: t('common.required'),
                            })}
                        />
                        {errors.email && (
                            <FieldError>{errors.email.message}</FieldError>
                        )}
                    </Field>

                    <SubmitButton
                        pending={send.isPending}
                        pendingLabel={t('forgot.submitting')}
                        disabled={cooldown.active}
                    >
                        {cooldown.active
                            ? t('common.retryIn', {
                                  seconds: cooldown.remaining,
                              })
                            : t('forgot.submit')}
                    </SubmitButton>
                </FieldGroup>
            </form>
        </AuthLayout>
    );
}
