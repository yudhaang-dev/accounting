import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link, useRouter } from '@tanstack/react-router';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AuthLayout } from '@/components/auth/auth-layout';
import { FormAlert } from '@/components/auth/form-alert';
import { SubmitButton } from '@/components/auth/submit-button';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { authApi } from '@/lib/auth-api';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { refreshMe } from '@/lib/session';

type Mode = 'code' | 'recovery';

export function TwoFactorPage({ redirect }: { redirect?: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const router = useRouter();

    const [mode, setMode] = useState<Mode>('code');
    const [code, setCode] = useState('');
    const [recoveryCode, setRecoveryCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);

    const otpRef = useRef<HTMLInputElement>(null);
    const recoveryRef = useRef<HTMLInputElement>(null);

    useDocumentTitle(t('twoFactor.pageTitle'));

    const verify = useMutation({
        mutationFn: authApi.twoFactorChallenge,
        onSuccess: async () => {
            await refreshMe(queryClient);
            router.history.push(redirect ?? '/');
        },
        onError: (failure) => {
            setAttempt((count) => count + 1);

            if (isApiError(failure) && failure.status === 422) {
                const rejected =
                    failure.first('code') ?? failure.first('recovery_code');

                // No field error means the half-open login is gone (timed out), not a wrong code.
                setError(
                    rejected
                        ? mode === 'code'
                            ? t('twoFactor.invalid')
                            : t('twoFactor.recoveryInvalid')
                        : t('twoFactor.expiredLogin'),
                );
            } else if (isApiError(failure) && failure.isRateLimited) {
                setError(t('login.rateLimited'));
            } else {
                setError(genericErrorMessage(failure, t));
            }

            // A wrong code is cleared so the next attempt starts clean.
            setCode('');
        },
    });

    // After a failure the input is enabled again; hand the cursor back to it.
    useEffect(() => {
        if (attempt > 0 && !verify.isPending) {
            (mode === 'code' ? otpRef : recoveryRef).current?.focus();
        }
    }, [attempt, mode, verify.isPending]);

    const switchMode = (next: Mode) => {
        setMode(next);
        setError(null);
        setCode('');
        setRecoveryCode('');
    };

    const isCode = mode === 'code';

    return (
        <AuthLayout
            title={isCode ? t('twoFactor.title') : t('twoFactor.recoveryTitle')}
            description={
                isCode
                    ? t('twoFactor.description')
                    : t('twoFactor.recoveryDescription')
            }
            footer={
                <Link
                    to="/login"
                    search={{ redirect }}
                    className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    {t('common.backToLogin')}
                </Link>
            }
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    setError(null);
                    verify.mutate(
                        isCode
                            ? { code }
                            : { recovery_code: recoveryCode.trim() },
                    );
                }}
            >
                <FieldGroup>
                    {error && <FormAlert attempt={attempt}>{error}</FormAlert>}

                    {isCode ? (
                        <Field className="items-center">
                            <FieldLabel htmlFor="otp" className="sr-only">
                                {t('twoFactor.codeLabel')}
                            </FieldLabel>
                            <InputOTP
                                id="otp"
                                ref={otpRef}
                                maxLength={6}
                                value={code}
                                onChange={setCode}
                                // Typing or pasting the 6th digit sends it; no extra click needed.
                                onComplete={(value) =>
                                    verify.mutate({ code: value })
                                }
                                pattern={REGEXP_ONLY_DIGITS}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                autoFocus
                                disabled={verify.isPending}
                                aria-invalid={error ? true : undefined}
                            >
                                <InputOTPGroup>
                                    {[0, 1, 2, 3, 4, 5].map((index) => (
                                        <InputOTPSlot
                                            key={index}
                                            index={index}
                                            aria-invalid={
                                                error ? true : undefined
                                            }
                                        />
                                    ))}
                                </InputOTPGroup>
                            </InputOTP>
                        </Field>
                    ) : (
                        <Field data-invalid={error ? true : undefined}>
                            <FieldLabel htmlFor="recovery_code">
                                {t('twoFactor.recoveryLabel')}
                            </FieldLabel>
                            <Input
                                id="recovery_code"
                                ref={recoveryRef}
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                autoFocus
                                placeholder="xxxxx-xxxxx"
                                value={recoveryCode}
                                onChange={(event) =>
                                    setRecoveryCode(event.target.value)
                                }
                                disabled={verify.isPending}
                                aria-invalid={error ? true : undefined}
                            />
                        </Field>
                    )}

                    <SubmitButton
                        pending={verify.isPending}
                        pendingLabel={t('twoFactor.verifying')}
                        disabled={
                            isCode
                                ? code.length < 6
                                : recoveryCode.trim() === ''
                        }
                    >
                        {t('twoFactor.submit')}
                    </SubmitButton>

                    <Button
                        type="button"
                        variant="ghost"
                        className="w-full text-muted-foreground"
                        disabled={verify.isPending}
                        onClick={() => switchMode(isCode ? 'recovery' : 'code')}
                    >
                        {isCode
                            ? t('twoFactor.useRecovery')
                            : t('twoFactor.useCode')}
                    </Button>
                </FieldGroup>
            </form>
        </AuthLayout>
    );
}
