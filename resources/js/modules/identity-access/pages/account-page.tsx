import {
    useMutation,
    useQuery,
    useQueryClient,
    type QueryClient,
} from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormDialog } from '@/components/form-dialog';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { accountApi, sessionsQuery } from '@/modules/identity-access/account';
import { PasswordField } from '@/modules/identity-access/components/password-field';
import { TwoFactorSection } from '@/modules/identity-access/components/two-factor-section';
import { useSignOut } from '@/modules/identity-access/hooks/use-sign-out';
import { meQuery, refreshMe, type Me } from '@/modules/identity-access/session';
import {
    workspacesQuery,
    type Workspace,
} from '@/modules/identity-access/workspaces';

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

function ProfileSection({ me }: { me: Me }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<{ name: string }>({
        defaultValues: { name: me.name },
    });
    const { errors } = form.formState;

    const save = useMutation({
        mutationFn: accountApi.rename,
        onSuccess: async () => {
            await refreshMe(queryClient);
            toast.success(t('account.profile.saved'));
        },
        onError: (error) => {
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
        <Section
            title={t('account.profile.title')}
            description={t('account.profile.description')}
        >
            <form
                noValidate
                className="max-w-md"
                onSubmit={form.handleSubmit(({ name }) => {
                    setFormError(null);
                    save.mutate(name.trim());
                })}
            >
                <FieldGroup>
                    {formError && <FormAlert>{formError}</FormAlert>}
                    <Field data-invalid={errors.name ? true : undefined}>
                        <FieldLabel htmlFor="account-name">
                            {t('account.profile.name')}
                        </FieldLabel>
                        <Input
                            id="account-name"
                            autoComplete="name"
                            aria-invalid={errors.name ? true : undefined}
                            {...form.register('name', {
                                required: t('account.profile.nameRequired'),
                            })}
                        />
                        {errors.name && (
                            <FieldError>{errors.name.message}</FieldError>
                        )}
                    </Field>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('common.save')}
                    </SubmitButton>
                </FieldGroup>
            </form>
        </Section>
    );
}

function EmailSection({ me }: { me: Me }) {
    const { t } = useTranslation();
    const [formError, setFormError] = useState<string | null>(null);
    const [sentTo, setSentTo] = useState<string | null>(null);
    const form = useForm<{ email: string; current_password: string }>({
        defaultValues: { email: '', current_password: '' },
    });
    const { errors } = form.formState;

    const request = useMutation({
        mutationFn: accountApi.requestEmailChange,
        onSuccess: (_data, input) => {
            setSentTo(input.email);
            form.reset();
        },
        onError: (error) => {
            if (isApiError(error) && error.isRateLimited) {
                setFormError(t('account.rateLimited'));

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
        <Section
            title={t('account.email.title')}
            description={t('account.email.description', { email: me.email })}
        >
            <form
                noValidate
                className="max-w-md"
                onSubmit={form.handleSubmit((values) => {
                    setFormError(null);
                    setSentTo(null);
                    request.mutate({
                        email: values.email.trim(),
                        current_password: values.current_password,
                    });
                })}
            >
                <FieldGroup>
                    {formError && <FormAlert>{formError}</FormAlert>}
                    {sentTo && (
                        <Alert role="status">
                            <AlertDescription>
                                {t('account.email.sent', { email: sentTo })}
                            </AlertDescription>
                        </Alert>
                    )}
                    <Field data-invalid={errors.email ? true : undefined}>
                        <FieldLabel htmlFor="account-new-email">
                            {t('account.email.new')}
                        </FieldLabel>
                        <Input
                            id="account-new-email"
                            type="email"
                            autoComplete="email"
                            aria-invalid={errors.email ? true : undefined}
                            {...form.register('email', {
                                required: t('account.email.required'),
                            })}
                        />
                        {errors.email && (
                            <FieldError>{errors.email.message}</FieldError>
                        )}
                    </Field>
                    <PasswordField
                        id="account-email-password"
                        label={t('account.currentPassword')}
                        autoComplete="current-password"
                        error={errors.current_password?.message}
                        {...form.register('current_password', {
                            required: t('account.passwordRequired'),
                        })}
                    />
                    <SubmitButton
                        className="w-auto"
                        pending={request.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('account.email.submit')}
                    </SubmitButton>
                </FieldGroup>
            </form>
        </Section>
    );
}

type PasswordValues = {
    current_password: string;
    password: string;
    password_confirmation: string;
};

function PasswordSection() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<PasswordValues>({
        defaultValues: {
            current_password: '',
            password: '',
            password_confirmation: '',
        },
    });
    const { errors } = form.formState;

    const change = useMutation({
        mutationFn: accountApi.changePassword,
        onSuccess: async () => {
            form.reset();
            await queryClient.invalidateQueries({
                queryKey: sessionsQuery.queryKey,
            });
            toast.success(t('account.password.saved'));
        },
        onError: (error) => {
            if (isApiError(error) && error.isRateLimited) {
                setFormError(t('account.rateLimited'));

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
        <Section
            title={t('account.password.title')}
            description={t('account.password.description')}
        >
            <form
                noValidate
                className="max-w-md"
                onSubmit={form.handleSubmit((values) => {
                    setFormError(null);
                    change.mutate(values);
                })}
            >
                <FieldGroup>
                    {formError && <FormAlert>{formError}</FormAlert>}
                    <PasswordField
                        id="account-current-password"
                        label={t('account.currentPassword')}
                        autoComplete="current-password"
                        error={errors.current_password?.message}
                        {...form.register('current_password', {
                            required: t('account.passwordRequired'),
                        })}
                    />
                    <PasswordField
                        id="account-new-password"
                        label={t('account.password.new')}
                        autoComplete="new-password"
                        error={errors.password?.message}
                        {...form.register('password', {
                            required: t('account.passwordRequired'),
                        })}
                    />
                    <PasswordField
                        id="account-password-confirmation"
                        label={t('account.password.confirm')}
                        autoComplete="new-password"
                        error={errors.password_confirmation?.message}
                        {...form.register('password_confirmation', {
                            required: t('account.passwordRequired'),
                            validate: (value) =>
                                value === form.getValues('password') ||
                                t('account.password.mismatch'),
                        })}
                    />
                    <SubmitButton
                        className="w-auto"
                        pending={change.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('account.password.submit')}
                    </SubmitButton>
                </FieldGroup>
            </form>
        </Section>
    );
}

/** A short, human label for a browser user agent; the full string stays in the tooltip. */
function deviceLabel(userAgent: string | null, fallback: string): string {
    if (!userAgent) {
        return fallback;
    }

    const browser = /Edg\//.test(userAgent)
        ? 'Edge'
        : /OPR\//.test(userAgent)
          ? 'Opera'
          : /Firefox\//.test(userAgent)
            ? 'Firefox'
            : /Chrome\//.test(userAgent)
              ? 'Chrome'
              : /Safari\//.test(userAgent)
                ? 'Safari'
                : null;
    const system = /Windows/.test(userAgent)
        ? 'Windows'
        : /Android/.test(userAgent)
          ? 'Android'
          : /iPhone|iPad/.test(userAgent)
            ? 'iOS'
            : /Mac OS X/.test(userAgent)
              ? 'macOS'
              : /Linux/.test(userAgent)
                ? 'Linux'
                : null;

    return [browser, system].filter(Boolean).join(' · ') || fallback;
}

function SessionsSection() {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const sessions = useQuery(sessionsQuery);
    const [error, setError] = useState<string | null>(null);

    const refresh = () =>
        queryClient.invalidateQueries({ queryKey: sessionsQuery.queryKey });

    const endOne = useMutation({
        mutationFn: accountApi.endSession,
        onSuccess: async () => {
            await refresh();
            toast.success(t('account.sessions.ended'));
        },
        onError: (failure) => setError(actionErrorMessage(failure, t)),
    });
    const endOthers = useMutation({
        mutationFn: accountApi.endOtherSessions,
        onSuccess: async () => {
            await refresh();
            toast.success(t('account.sessions.endedOthers'));
        },
        onError: (failure) => setError(actionErrorMessage(failure, t)),
    });

    const others = (sessions.data ?? []).filter((s) => !s.is_current);
    const format = new Intl.DateTimeFormat(i18n.language, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return (
        <Section
            title={t('account.sessions.title')}
            description={t('account.sessions.description')}
        >
            {error && <FormAlert>{error}</FormAlert>}
            {sessions.isPending ? (
                <Skeleton className="h-16 w-full" />
            ) : sessions.isError ? (
                <FormAlert>{actionErrorMessage(sessions.error, t)}</FormAlert>
            ) : (
                <div className="flex flex-col gap-3">
                    <ul className="flex flex-col divide-y rounded-md border">
                        {sessions.data.map((session) => (
                            <li
                                key={session.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5"
                            >
                                <div className="flex flex-col text-sm">
                                    <span
                                        className="font-medium"
                                        title={session.user_agent ?? undefined}
                                    >
                                        {deviceLabel(
                                            session.user_agent,
                                            t('account.sessions.unknownDevice'),
                                        )}
                                        {session.is_current && (
                                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                {t('account.sessions.current')}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {[
                                            session.ip_address,
                                            format.format(
                                                new Date(
                                                    session.last_active_at,
                                                ),
                                            ),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </div>
                                {!session.is_current && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={endOne.isPending}
                                        onClick={() => {
                                            setError(null);
                                            endOne.mutate(session.id);
                                        }}
                                    >
                                        {t('account.sessions.end')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                    {others.length > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            className="w-fit"
                            disabled={endOthers.isPending}
                            onClick={() => {
                                setError(null);
                                endOthers.mutate();
                            }}
                        >
                            {t('account.sessions.endOthers')}
                        </Button>
                    )}
                </div>
            )}
        </Section>
    );
}

function leaveErrorMessage(
    error: unknown,
    t: ReturnType<typeof useTranslation>['t'],
): string {
    // The refusal for the last administrator comes as an error on `workspace`, not as a field of a form.
    return (
        (isApiError(error) ? error.first('workspace') : undefined) ??
        actionErrorMessage(error, t)
    );
}

async function refreshAccess(queryClient: QueryClient) {
    await queryClient.invalidateQueries({ queryKey: workspacesQuery.queryKey });
    await queryClient.invalidateQueries({ queryKey: meQuery.queryKey });
}

function WorkspacesSection({ workspaces }: { workspaces: Workspace[] }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [leaving, setLeaving] = useState<Workspace | null>(null);
    const [error, setError] = useState<string | null>(null);

    const leave = useMutation({
        mutationFn: (workspace: Workspace) =>
            accountApi.leaveWorkspace(workspace.id),
        onSuccess: async () => {
            setLeaving(null);
            await refreshAccess(queryClient);
            toast.success(t('account.workspaces.left'));
        },
        onError: (failure) => setError(leaveErrorMessage(failure, t)),
    });

    return (
        <Section
            title={t('account.workspaces.title')}
            description={t('account.workspaces.description')}
        >
            {workspaces.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('account.workspaces.none')}
                </p>
            ) : (
                <ul className="flex flex-col divide-y rounded-md border">
                    {workspaces.map((workspace) => (
                        <li
                            key={workspace.id}
                            className="flex items-center justify-between gap-3 px-3 py-2.5"
                        >
                            <span className="text-sm font-medium">
                                {workspace.name}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setLeaving(workspace);
                                }}
                            >
                                {t('account.workspaces.leave')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <ConfirmDialog
                open={leaving !== null}
                onOpenChange={(open) => !open && setLeaving(null)}
                title={t('account.workspaces.leaveTitle', {
                    name: leaving?.name ?? '',
                })}
                description={t('account.workspaces.leaveDescription')}
                confirmLabel={t('account.workspaces.leave')}
                destructive
                pending={leave.isPending}
                error={error}
                onConfirm={() => leaving && leave.mutate(leaving)}
            />
        </Section>
    );
}

function DeactivateSection() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const signedOut = useSignOut();
    const [open, setOpen] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<{ current_password: string }>({
        defaultValues: { current_password: '' },
    });
    const { errors } = form.formState;

    const deactivate = useMutation({
        mutationFn: accountApi.deactivate,
        onSuccess: async () => {
            // The server already ended the session; the logout call only clears what the browser kept.
            queryClient.clear();
            signedOut.mutate();
        },
        onError: (error) => {
            if (isApiError(error) && error.isRateLimited) {
                setFormError(t('account.rateLimited'));

                return;
            }

            if (isApiError(error) && error.status === 422) {
                // The last administrator of a workspace is refused under `workspace`, not a field.
                const workspace = error.first('workspace');

                if (workspace) {
                    setFormError(workspace);

                    return;
                }

                if (applyApiErrors(form.setError, error)) {
                    return;
                }
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
        <Section
            title={t('account.deactivate.title')}
            description={t('account.deactivate.description')}
        >
            <Button
                type="button"
                variant="destructive"
                onClick={() => {
                    setFormError(null);
                    form.reset();
                    setOpen(true);
                }}
            >
                {t('account.deactivate.open')}
            </Button>
            <FormDialog
                open={open}
                onOpenChange={(next) => !deactivate.isPending && setOpen(next)}
                title={t('account.deactivate.confirmTitle')}
                description={t('account.deactivate.confirmDescription')}
            >
                <form
                    noValidate
                    onSubmit={form.handleSubmit(({ current_password }) => {
                        setFormError(null);
                        deactivate.mutate(current_password);
                    })}
                >
                    <FieldGroup>
                        <p className="text-sm text-muted-foreground">
                            {t('account.deactivate.confirmDescription')}
                        </p>
                        {formError && <FormAlert>{formError}</FormAlert>}
                        <PasswordField
                            id="account-deactivate-password"
                            label={t('account.currentPassword')}
                            autoComplete="current-password"
                            error={errors.current_password?.message}
                            {...form.register('current_password', {
                                required: t('account.passwordRequired'),
                            })}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={deactivate.isPending}
                                onClick={() => setOpen(false)}
                            >
                                {t('common.cancel')}
                            </Button>
                            <SubmitButton
                                className="w-auto"
                                variant="destructive"
                                pending={deactivate.isPending}
                                pendingLabel={t('common.saving')}
                            >
                                {t('account.deactivate.confirm')}
                            </SubmitButton>
                        </div>
                    </FieldGroup>
                </form>
            </FormDialog>
        </Section>
    );
}

/**
 * The user's own account (IA-FR-01, IA-FR-04): name, sign-in address, password, active sessions, leaving
 * workspaces, and deactivation. Two-step verification is on the same page; passkeys follow (D2d3c).
 */
export function AccountPage({
    me,
    workspaces,
}: {
    me: Me;
    workspaces: Workspace[];
}) {
    const { t } = useTranslation();

    useDocumentTitle(t('account.pageTitle'));

    return (
        <div className="flex max-w-3xl flex-col gap-6">
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('account.title')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('account.description')}
                </p>
            </div>
            <ProfileSection me={me} />
            <EmailSection me={me} />
            <PasswordSection />
            <TwoFactorSection me={me} />
            <SessionsSection />
            <WorkspacesSection workspaces={workspaces} />
            <DeactivateSection />
        </div>
    );
}
