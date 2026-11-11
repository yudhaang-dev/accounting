import { useMutation } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { FieldGroup } from '@/components/ui/field';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { twoFactorApi } from '@/modules/identity-access/account';
import { PasswordField } from '@/modules/identity-access/components/password-field';

/** True when the server wants the password again before a sensitive action (HTTP 423). */
export const needsPasswordConfirmation = (error: unknown): boolean =>
    isApiError(error) && error.status === 423;

/**
 * Fortify asks for the password again before it changes two-step verification. A screen calls `ask` with
 * the action that was refused (423); once the password is right the action runs again, so the user never
 * has to repeat the click.
 */
export function usePasswordConfirmation(): {
    ask: (retry: () => void) => void;
    dialog: ReactNode;
} {
    const { t } = useTranslation();
    const [retry, setRetry] = useState<(() => void) | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<{ password: string }>({
        defaultValues: { password: '' },
    });
    const { errors } = form.formState;

    const confirm = useMutation({
        mutationFn: twoFactorApi.confirmPassword,
        onSuccess: () => {
            const next = retry;

            setRetry(null);
            form.reset();
            next?.();
        },
        onError: (error) => {
            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error)
            ) {
                return;
            }

            setFormError(
                isApiError(error) && error.isRateLimited
                    ? t('account.rateLimited')
                    : actionErrorMessage(error, t),
            );
        },
    });

    const close = () => {
        if (!confirm.isPending) {
            setRetry(null);
        }
    };

    return {
        ask: (next) => {
            setFormError(null);
            form.reset();
            setRetry(() => next);
        },
        dialog: (
            <FormDialog
                open={retry !== null}
                onOpenChange={(open) => !open && close()}
                title={t('account.confirmPassword.title')}
                description={t('account.confirmPassword.description')}
            >
                <form
                    noValidate
                    onSubmit={form.handleSubmit(({ password }) => {
                        setFormError(null);
                        confirm.mutate(password);
                    })}
                >
                    <FieldGroup>
                        <p className="text-sm text-muted-foreground">
                            {t('account.confirmPassword.description')}
                        </p>
                        {formError && <FormAlert>{formError}</FormAlert>}
                        <PasswordField
                            id="confirm-password"
                            label={t('account.currentPassword')}
                            autoComplete="current-password"
                            autoFocus
                            error={errors.password?.message}
                            {...form.register('password', {
                                required: t('account.passwordRequired'),
                            })}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={confirm.isPending}
                                onClick={close}
                            >
                                {t('common.cancel')}
                            </Button>
                            <SubmitButton
                                className="w-auto"
                                pending={confirm.isPending}
                                pendingLabel={t('common.saving')}
                            >
                                {t('account.confirmPassword.submit')}
                            </SubmitButton>
                        </div>
                    </FieldGroup>
                </form>
            </FormDialog>
        ),
    };
}
