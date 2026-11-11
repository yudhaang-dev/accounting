import { useMutation, useQueryClient } from '@tanstack/react-query';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { twoFactorApi } from '@/modules/identity-access/account';
import {
    needsPasswordConfirmation,
    usePasswordConfirmation,
} from '@/modules/identity-access/hooks/use-password-confirmation';
import { refreshMe, type Me } from '@/modules/identity-access/session';

type Setup = { svg: string; secret: string };

/** The QR code comes from the server as SVG text; an image element shows it without running anything in it. */
const svgSource = (svg: string) =>
    `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;

function RecoveryCodes({ codes }: { codes: string[] }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm text-muted-foreground">
                {t('account.twoFactor.recoveryHint')}
            </p>
            <ul
                className="grid max-w-sm grid-cols-2 gap-x-6 gap-y-1 rounded-md border bg-muted/40 p-3 font-mono text-sm"
                aria-label={t('account.twoFactor.recoveryCodes')}
            >
                {codes.map((code) => (
                    <li key={code}>{code}</li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Two-step verification of the signed-in user (IA-FR-04): set up with a QR code and a first code, show and
 * renew the recovery codes, and turn it off. Fortify asks for the password again before each of these;
 * the refused action runs again by itself once the password is right.
 */
export function TwoFactorSection({ me }: { me: Me }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const confirmation = usePasswordConfirmation();
    const [setup, setSetup] = useState<Setup | null>(null);
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState<string | null>(null);
    const [codes, setCodes] = useState<string[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [disabling, setDisabling] = useState(false);

    /** Runs a call; when the password must be confirmed first, asks for it and runs the call again. */
    const guarded =
        <T,>(call: () => Promise<T>, retry: () => void) =>
        async () => {
            try {
                return await call();
            } catch (failure) {
                if (needsPasswordConfirmation(failure)) {
                    confirmation.ask(retry);
                }

                throw failure;
            }
        };

    const fail = (failure: unknown) => {
        if (!needsPasswordConfirmation(failure)) {
            setError(
                isApiError(failure) && failure.isRateLimited
                    ? t('account.rateLimited')
                    : actionErrorMessage(failure, t),
            );
        }
    };

    const start = useMutation({
        mutationFn: () =>
            guarded(
                async () => {
                    await twoFactorApi.enable();

                    const [qr, secret] = await Promise.all([
                        twoFactorApi.qrCode(),
                        twoFactorApi.secretKey(),
                    ]);

                    return { svg: qr.svg, secret };
                },
                () => start.mutate(),
            )(),
        onSuccess: (next) => {
            setError(null);
            setCode('');
            setCodeError(null);
            setSetup(next);
        },
        onError: fail,
    });

    const verify = useMutation({
        mutationFn: (value: string) =>
            guarded(
                async () => {
                    await twoFactorApi.confirm(value);

                    return twoFactorApi.recoveryCodes();
                },
                () => verify.mutate(value),
            )(),
        onSuccess: async (recovery) => {
            setSetup(null);
            setCodes(recovery);
            await refreshMe(queryClient);
            toast.success(t('account.twoFactor.enabled'));
        },
        onError: (failure) => {
            setCode('');

            if (isApiError(failure) && failure.status === 422) {
                setCodeError(t('account.twoFactor.invalidCode'));

                return;
            }

            fail(failure);
        },
    });

    const show = useMutation({
        mutationFn: () =>
            guarded(twoFactorApi.recoveryCodes, () => show.mutate())(),
        onSuccess: (recovery) => {
            setError(null);
            setCodes(recovery);
        },
        onError: fail,
    });

    const renew = useMutation({
        mutationFn: () =>
            guarded(
                async () => {
                    await twoFactorApi.regenerateRecoveryCodes();

                    return twoFactorApi.recoveryCodes();
                },
                () => renew.mutate(),
            )(),
        onSuccess: (recovery) => {
            setError(null);
            setCodes(recovery);
            toast.success(t('account.twoFactor.renewed'));
        },
        onError: fail,
    });

    const disable = useMutation({
        mutationFn: () =>
            guarded(twoFactorApi.disable, () => disable.mutate())(),
        onSuccess: async () => {
            setDisabling(false);
            setCodes(null);
            await refreshMe(queryClient);
            toast.success(t('account.twoFactor.disabled'));
        },
        onError: (failure) => {
            // A refused call asks for the password in its own dialog and runs again after it; two stacked
            // dialogs would hide each other.
            setDisabling(false);
            fail(failure);
        },
    });

    const busy =
        start.isPending ||
        verify.isPending ||
        show.isPending ||
        renew.isPending ||
        disable.isPending;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('account.twoFactor.title')}</CardTitle>
                <CardDescription>
                    {t('account.twoFactor.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {error && <FormAlert>{error}</FormAlert>}

                {setup ? (
                    <div className="flex max-w-md flex-col gap-4">
                        <p className="text-sm">{t('account.twoFactor.scan')}</p>
                        <img
                            src={svgSource(setup.svg)}
                            alt={t('account.twoFactor.qrAlt')}
                            className="size-48 rounded-md border bg-white p-2"
                        />
                        <p className="text-sm text-muted-foreground">
                            {t('account.twoFactor.secretHint')}{' '}
                            <span className="font-mono break-all text-foreground">
                                {setup.secret}
                            </span>
                        </p>
                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="two-factor-setup-code"
                                className="text-sm font-medium"
                            >
                                {t('account.twoFactor.codeLabel')}
                            </label>
                            <InputOTP
                                id="two-factor-setup-code"
                                maxLength={6}
                                value={code}
                                onChange={(value) => {
                                    setCode(value);
                                    setCodeError(null);
                                }}
                                onComplete={(value) => verify.mutate(value)}
                                pattern={REGEXP_ONLY_DIGITS}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                disabled={verify.isPending}
                                aria-invalid={codeError ? true : undefined}
                            >
                                <InputOTPGroup>
                                    {[0, 1, 2, 3, 4, 5].map((index) => (
                                        <InputOTPSlot
                                            key={index}
                                            index={index}
                                        />
                                    ))}
                                </InputOTPGroup>
                            </InputOTP>
                            {codeError && (
                                <p
                                    className="text-sm text-destructive"
                                    role="alert"
                                >
                                    {codeError}
                                </p>
                            )}
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            className="w-fit"
                            disabled={verify.isPending}
                            onClick={() => setSetup(null)}
                        >
                            {t('common.cancel')}
                        </Button>
                    </div>
                ) : me.two_factor_enabled ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => show.mutate()}
                        >
                            {show.isPending && <Spinner aria-hidden />}
                            {t('account.twoFactor.showCodes')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => renew.mutate()}
                        >
                            {renew.isPending && <Spinner aria-hidden />}
                            {t('account.twoFactor.renewCodes')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={busy}
                            onClick={() => {
                                setError(null);
                                setDisabling(true);
                            }}
                        >
                            {t('account.twoFactor.disable')}
                        </Button>
                    </div>
                ) : (
                    <Button
                        type="button"
                        className="w-fit"
                        disabled={busy}
                        onClick={() => {
                            setError(null);
                            setCodes(null);
                            start.mutate();
                        }}
                    >
                        {start.isPending && <Spinner aria-hidden />}
                        {t('account.twoFactor.enable')}
                    </Button>
                )}

                {codes && <RecoveryCodes codes={codes} />}
            </CardContent>

            <ConfirmDialog
                open={disabling}
                onOpenChange={setDisabling}
                title={t('account.twoFactor.disableTitle')}
                description={t('account.twoFactor.disableDescription')}
                confirmLabel={t('account.twoFactor.disable')}
                destructive
                pending={disable.isPending}
                onConfirm={() => disable.mutate()}
            />
            {confirmation.dialog}
        </Card>
    );
}
