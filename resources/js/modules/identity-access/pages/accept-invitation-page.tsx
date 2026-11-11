import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useRouter } from '@tanstack/react-router';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { AuthLayout } from '@/components/auth-layout';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { acceptInvitation } from '@/modules/identity-access/access';
import { PasswordField } from '@/modules/identity-access/components/password-field';
import { meQuery, refreshMe } from '@/modules/identity-access/session';
import { workspacesQuery } from '@/modules/identity-access/workspaces';

type Values = { name: string; password: string; password_confirmation: string };

const MIN_LENGTH = 8;

/**
 * Where the link of an invitation e-mail lands (IA-FR-01, ADR-0021). Joining is an explicit click rather
 * than something the page does on load, so a link preview or a refresh cannot accept on someone's behalf.
 * Someone signed in as the invited address accepts with one click; a visitor with no account makes one
 * here, with a name and a password, and is signed in. An address that already has an account has to sign
 * in first.
 */
export function AcceptInvitationPage({ token }: { token: string | undefined }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const router = useRouter();
    const me = useQuery(meQuery);
    const [error, setError] = useState<string | null>(null);
    const [accountExists, setAccountExists] = useState(false);

    useDocumentTitle(t('invitation.pageTitle'));

    const {
        register,
        handleSubmit,
        setError: setFieldError,
        getValues,
        formState: { errors },
    } = useForm<Values>({
        mode: 'onTouched',
        defaultValues: { name: '', password: '', password_confirmation: '' },
    });

    const accept = useMutation({
        mutationFn: (account?: Values) =>
            acceptInvitation(token ?? '', account),
        onSuccess: async (workspace) => {
            // Signed in by the server when the account was just made; the new workspace has to be in the
            // list before its address is opened.
            await refreshMe(queryClient);

            if (workspace === null) {
                await navigate({ to: '/' });

                return;
            }

            await queryClient.fetchQuery({ ...workspacesQuery, staleTime: 0 });
            await navigate({
                to: '/w/$companyId',
                params: { companyId: workspace.company_id },
            });
        },
        onError: (failure) => {
            if (isApiError(failure) && failure.status === 401) {
                setAccountExists(true);

                return;
            }

            if (isApiError(failure) && failure.first('password')) {
                setFieldError('password', {
                    message: failure.first('password'),
                });

                return;
            }

            setError(actionErrorMessage(failure, t));
        },
    });

    const signedIn = me.data != null;

    return (
        <AuthLayout
            title={t('invitation.title')}
            description={
                !token
                    ? t('invitation.missingToken')
                    : signedIn
                      ? t('invitation.description')
                      : t('invitation.newAccountDescription')
            }
        >
            <div className="flex flex-col gap-4">
                {error && <FormAlert>{error}</FormAlert>}
                {accountExists && (
                    <FormAlert>
                        {t('invitation.accountExists')}{' '}
                        <Link
                            to="/login"
                            search={{ redirect: router.state.location.href }}
                            className="font-medium underline underline-offset-4"
                        >
                            {t('invitation.signIn')}
                        </Link>
                    </FormAlert>
                )}

                {!token ? (
                    <Button
                        variant="outline"
                        onClick={() => navigate({ to: '/' })}
                    >
                        {t('invitation.home')}
                    </Button>
                ) : me.isPending ? null : signedIn ? (
                    <SubmitButton
                        pending={accept.isPending}
                        pendingLabel={t('invitation.accepting')}
                        onClick={() => {
                            setError(null);
                            setAccountExists(false);
                            accept.mutate(undefined);
                        }}
                    >
                        {t('invitation.accept')}
                    </SubmitButton>
                ) : (
                    <form
                        noValidate
                        onSubmit={handleSubmit((values) => {
                            setError(null);
                            setAccountExists(false);
                            accept.mutate(values);
                        })}
                    >
                        <FieldGroup>
                            <Field
                                data-invalid={errors.name ? true : undefined}
                            >
                                <FieldLabel htmlFor="name">
                                    {t('invitation.name')}
                                </FieldLabel>
                                <Input
                                    id="name"
                                    autoComplete="name"
                                    autoFocus
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                    {...register('name', {
                                        required: t('common.required'),
                                    })}
                                />
                                {errors.name && (
                                    <FieldError>
                                        {errors.name.message}
                                    </FieldError>
                                )}
                            </Field>

                            <PasswordField
                                id="password"
                                label={t('invitation.password')}
                                autoComplete="new-password"
                                hint={t('invitation.passwordHint')}
                                error={errors.password?.message}
                                {...register('password', {
                                    required: t('common.required'),
                                    minLength: {
                                        value: MIN_LENGTH,
                                        message: t('invitation.passwordHint'),
                                    },
                                })}
                            />

                            <PasswordField
                                id="password_confirmation"
                                label={t('invitation.confirmPassword')}
                                autoComplete="new-password"
                                error={errors.password_confirmation?.message}
                                {...register('password_confirmation', {
                                    required: t('common.required'),
                                    validate: (value) =>
                                        value === getValues('password') ||
                                        t('invitation.mismatch'),
                                })}
                            />

                            <SubmitButton
                                pending={accept.isPending}
                                pendingLabel={t('invitation.accepting')}
                            >
                                {t('invitation.createAccount')}
                            </SubmitButton>
                        </FieldGroup>
                    </form>
                )}
            </div>
        </AuthLayout>
    );
}
