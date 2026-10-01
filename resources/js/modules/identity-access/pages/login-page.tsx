import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useRouter } from '@tanstack/react-router';
import { Passkeys, UserCancelledError } from '@laravel/passkeys';
import { KeyRoundIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { AuthLayout } from '@/components/auth-layout';
import { FormAlert } from '@/components/form-alert';
import { PasswordField } from '@/modules/identity-access/components/password-field';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldSeparator,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useCountdown } from '@/hooks/use-countdown';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { authApi } from '@/modules/identity-access/auth-api';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { refreshMe } from '@/modules/identity-access/session';

type Values = { email: string; password: string };

export function LoginPage({ redirect }: { redirect?: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const router = useRouter();
    const navigate = useNavigate();
    const countdown = useCountdown();

    const [remember, setRemember] = useState(false);
    const rememberRef = useRef(remember);
    const [formError, setFormError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    const [passkeyPending, setPasskeyPending] = useState(false);

    useDocumentTitle(t('login.pageTitle'));

    const {
        register,
        handleSubmit,
        setFocus,
        getValues,
        formState: { errors },
    } = useForm<Values>({ defaultValues: { email: '', password: '' } });

    useEffect(() => {
        rememberRef.current = remember;
    }, [remember]);

    const fail = (message: string) => {
        setFormError(message);
        setAttempt((count) => count + 1);
    };

    const signedIn = async () => {
        await refreshMe(queryClient);
        router.history.push(redirect ?? '/');
    };

    const login = useMutation({
        mutationFn: authApi.login,
        onSuccess: async (result) => {
            if (result.two_factor) {
                await navigate({
                    to: '/two-factor-challenge',
                    search: { redirect },
                });

                return;
            }

            await signedIn();
        },
        onError: (error) => {
            if (isApiError(error) && error.isRateLimited) {
                countdown.start(error.retryAfter ?? 60);
                fail(t('login.rateLimited'));

                return;
            }

            if (isApiError(error) && error.status === 422) {
                fail(error.first('email') ?? error.message);

                // Put the cursor back where the fix is: the password, selected for retyping.
                setFocus('password', { shouldSelect: true });

                return;
            }

            fail(genericErrorMessage(error, t));
        },
    });

    // Passkeys: a button for explicit use, plus the browser's own autofill picker on the email field.
    const passkeySupported = Passkeys.isSupported();

    useEffect(() => {
        if (!passkeySupported) {
            return;
        }

        let active = true;

        void Passkeys.isAutofillSupported().then(async (supported) => {
            if (!supported || !active) {
                return;
            }

            const result = await Passkeys.autofill({
                remember: () => rememberRef.current,
            }).catch(() => undefined);

            if (result && active) {
                await signedIn();
            }
        });

        return () => {
            active = false;
            Passkeys.cancel();
        };
        // `signedIn` only closes over stable references.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [passkeySupported]);

    const signInWithPasskey = async () => {
        setFormError(null);
        setPasskeyPending(true);
        Passkeys.cancel();

        try {
            await Passkeys.verify({ remember: () => rememberRef.current });
            await signedIn();
        } catch (error) {
            // Closing the system dialog is a choice, not an error.
            if (!(error instanceof UserCancelledError)) {
                fail(t('login.passkeyFailed'));
            }
        } finally {
            setPasskeyPending(false);
        }
    };

    const pending = login.isPending || passkeyPending;
    const throttled = countdown.active;

    const invalid = formError !== null && !throttled;

    return (
        <AuthLayout
            title={t('login.title')}
            description={t('login.description')}
        >
            <form
                noValidate
                onSubmit={handleSubmit((values) => {
                    setFormError(null);
                    login.mutate({ ...values, remember });
                })}
            >
                <FieldGroup>
                    {formError && (
                        <FormAlert attempt={attempt}>
                            {formError}
                            {throttled && (
                                <>
                                    {' '}
                                    {t('common.retryIn', {
                                        seconds: countdown.remaining,
                                    })}
                                </>
                            )}
                        </FormAlert>
                    )}

                    <Field
                        data-invalid={
                            errors.email || invalid ? true : undefined
                        }
                    >
                        <FieldLabel htmlFor="email">
                            {t('common.email')}
                        </FieldLabel>
                        <Input
                            id="email"
                            type="email"
                            inputMode="email"
                            autoComplete="username webauthn"
                            placeholder={t('login.emailPlaceholder')}
                            autoFocus
                            aria-invalid={
                                errors.email || invalid ? true : undefined
                            }
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
                        label={t('common.password')}
                        autoComplete="current-password"
                        error={
                            errors.password
                                ? errors.password.message
                                : undefined
                        }
                        labelAction={
                            <Link
                                to="/forgot-password"
                                search={{
                                    email: getValues('email') || undefined,
                                }}
                                className="text-sm text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                            >
                                {t('login.forgot')}
                            </Link>
                        }
                        {...register('password', {
                            required: t('common.required'),
                        })}
                    />

                    <Field orientation="horizontal">
                        <Checkbox
                            id="remember"
                            checked={remember}
                            onCheckedChange={(value) =>
                                setRemember(value === true)
                            }
                        />
                        <FieldLabel htmlFor="remember" className="font-normal">
                            {t('login.remember')}
                        </FieldLabel>
                    </Field>

                    <SubmitButton
                        pending={login.isPending}
                        pendingLabel={t('login.submitting')}
                        disabled={passkeyPending || throttled}
                    >
                        {throttled
                            ? t('common.retryIn', {
                                  seconds: countdown.remaining,
                              })
                            : t('login.submit')}
                    </SubmitButton>

                    {passkeySupported && (
                        <>
                            <FieldSeparator>{t('login.or')}</FieldSeparator>
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full"
                                disabled={pending}
                                aria-busy={passkeyPending}
                                onClick={() => void signInWithPasskey()}
                            >
                                {passkeyPending ? (
                                    <Spinner
                                        data-icon="inline-start"
                                        aria-hidden
                                    />
                                ) : (
                                    <KeyRoundIcon data-icon="inline-start" />
                                )}
                                {passkeyPending
                                    ? t('login.passkeyWaiting')
                                    : t('login.passkey')}
                            </Button>
                        </>
                    )}
                </FieldGroup>
            </form>
        </AuthLayout>
    );
}
