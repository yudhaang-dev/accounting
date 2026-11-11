import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    NotSupportedError,
    PasskeyExistsError,
    Passkeys,
    UserCancelledError,
} from '@laravel/passkeys';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    passkeyApi,
    passkeysQuery,
    type AccountPasskey,
} from '@/modules/identity-access/account';
import {
    needsPasswordConfirmation,
    usePasswordConfirmation,
} from '@/modules/identity-access/hooks/use-password-confirmation';

/**
 * Passkeys of the signed-in user (IA-FR-04): list, add with a name, remove. The password is confirmed before
 * the browser ceremony starts, because the library cannot tell a refusal for it from any other failure.
 */
export function PasskeysSection() {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const confirmation = usePasswordConfirmation();
    const passkeys = useQuery(passkeysQuery);
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<AccountPasskey | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const form = useForm<{ name: string }>({ defaultValues: { name: '' } });
    const { errors } = form.formState;
    const supported = Passkeys.isSupported();

    const refresh = () =>
        queryClient.invalidateQueries({ queryKey: passkeysQuery.queryKey });

    const register = useMutation({
        mutationFn: async (name: string) => {
            if (!(await passkeyApi.passwordConfirmed())) {
                confirmation.ask(() => register.mutate(name));

                return false;
            }

            await Passkeys.register({ name });

            return true;
        },
        onSuccess: async (done) => {
            if (!done) {
                setAdding(false);

                return;
            }

            setAdding(false);
            await refresh();
            toast.success(t('account.passkeys.added'));
        },
        onError: (failure) => {
            if (failure instanceof UserCancelledError) {
                return;
            }

            setFormError(
                failure instanceof PasskeyExistsError
                    ? t('account.passkeys.exists')
                    : failure instanceof NotSupportedError
                      ? t('account.passkeys.unsupported')
                      : actionErrorMessage(failure, t),
            );
        },
    });

    const remove = useMutation({
        mutationFn: (passkey: AccountPasskey) => passkeyApi.remove(passkey.id),
        onSuccess: async () => {
            setRemoving(null);
            await refresh();
            toast.success(t('account.passkeys.removed'));
        },
        onError: (failure, passkey) => {
            if (needsPasswordConfirmation(failure)) {
                setRemoving(null);
                confirmation.ask(() => remove.mutate(passkey));

                return;
            }

            setError(
                isApiError(failure) && failure.isRateLimited
                    ? t('account.rateLimited')
                    : actionErrorMessage(failure, t),
            );
        },
    });

    const format = new Intl.DateTimeFormat(i18n.language, {
        dateStyle: 'medium',
    });
    const day = (iso: string | null) =>
        iso ? format.format(new Date(iso)) : null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('account.passkeys.title')}</CardTitle>
                <CardDescription>
                    {t('account.passkeys.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {error && <FormAlert>{error}</FormAlert>}
                {passkeys.isPending ? (
                    <Skeleton className="h-12 w-full" />
                ) : passkeys.isError ? (
                    <FormAlert>
                        {actionErrorMessage(passkeys.error, t)}
                    </FormAlert>
                ) : passkeys.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('account.passkeys.none')}
                    </p>
                ) : (
                    <ul className="flex flex-col divide-y rounded-md border">
                        {passkeys.data.map((passkey) => (
                            <li
                                key={passkey.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5"
                            >
                                <div className="flex flex-col text-sm">
                                    <span className="font-medium">
                                        {passkey.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {[
                                            passkey.authenticator,
                                            day(passkey.created_at) &&
                                                t('account.passkeys.created', {
                                                    date: day(
                                                        passkey.created_at,
                                                    ),
                                                }),
                                            passkey.last_used_at
                                                ? t(
                                                      'account.passkeys.lastUsed',
                                                      {
                                                          date: day(
                                                              passkey.last_used_at,
                                                          ),
                                                      },
                                                  )
                                                : t(
                                                      'account.passkeys.neverUsed',
                                                  ),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setError(null);
                                        setRemoving(passkey);
                                    }}
                                >
                                    {t('account.passkeys.remove')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {supported ? (
                    <Button
                        type="button"
                        className="w-fit"
                        onClick={() => {
                            setFormError(null);
                            setError(null);
                            form.reset();
                            setAdding(true);
                        }}
                    >
                        {t('account.passkeys.add')}
                    </Button>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('account.passkeys.unsupported')}
                    </p>
                )}
            </CardContent>

            <FormDialog
                open={adding}
                onOpenChange={(open) => !register.isPending && setAdding(open)}
                title={t('account.passkeys.addTitle')}
                description={t('account.passkeys.addDescription')}
            >
                <form
                    noValidate
                    onSubmit={form.handleSubmit(({ name }) => {
                        setFormError(null);
                        register.mutate(name.trim());
                    })}
                >
                    <FieldGroup>
                        {formError && <FormAlert>{formError}</FormAlert>}
                        <Field data-invalid={errors.name ? true : undefined}>
                            <FieldLabel htmlFor="passkey-name">
                                {t('account.passkeys.name')}
                            </FieldLabel>
                            <Input
                                id="passkey-name"
                                autoFocus
                                placeholder={t(
                                    'account.passkeys.namePlaceholder',
                                )}
                                aria-invalid={errors.name ? true : undefined}
                                {...form.register('name', {
                                    validate: (value) =>
                                        value.trim() !== '' ||
                                        t('account.passkeys.nameRequired'),
                                })}
                            />
                            <FieldDescription>
                                {t('account.passkeys.nameHint')}
                            </FieldDescription>
                            {errors.name && (
                                <FieldError>{errors.name.message}</FieldError>
                            )}
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={register.isPending}
                                onClick={() => setAdding(false)}
                            >
                                {t('common.cancel')}
                            </Button>
                            <SubmitButton
                                className="w-auto"
                                pending={register.isPending}
                                pendingLabel={t('account.passkeys.waiting')}
                            >
                                {t('account.passkeys.continue')}
                            </SubmitButton>
                        </div>
                    </FieldGroup>
                </form>
            </FormDialog>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={t('account.passkeys.removeTitle', {
                    name: removing?.name ?? '',
                })}
                description={t('account.passkeys.removeDescription')}
                confirmLabel={t('account.passkeys.remove')}
                destructive
                pending={remove.isPending}
                onConfirm={() => removing && remove.mutate(removing)}
            />
            {confirmation.dialog}
        </Card>
    );
}
