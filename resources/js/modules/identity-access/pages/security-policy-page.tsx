import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    saveSecurityPolicy,
    securityPolicyQuery,
    type PermissionKey,
    type SecurityPolicy,
} from '@/modules/identity-access/access';
import { PermissionChecklist } from '@/modules/identity-access/components/permission-checklist';

type Values = {
    two_factor_permissions: string[];
    /** Minutes as typed; empty means sessions never time out. */
    idle: string;
    /** One IP address or CIDR range per line. */
    allowlist: string;
};

const MAX_IDLE_MINUTES = 1440;

const lines = (text: string): string[] =>
    text
        .split(/[\s,]+/)
        .map((line) => line.trim())
        .filter(Boolean);

/** The API names its fields differently from the form; every array element error belongs to its list. */
const fieldFor = (apiField: string): keyof Values | undefined =>
    apiField.startsWith('client_ip_allowlist')
        ? 'allowlist'
        : apiField.startsWith('two_factor_permissions')
          ? 'two_factor_permissions'
          : apiField === 'idle_timeout_minutes'
            ? 'idle'
            : undefined;

function PolicyForm({
    companyId,
    policy,
}: {
    companyId: string;
    policy: SecurityPolicy;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            two_factor_permissions: policy.two_factor_permissions,
            idle:
                policy.idle_timeout_minutes === null
                    ? ''
                    : String(policy.idle_timeout_minutes),
            allowlist: policy.client_ip_allowlist.join('\n'),
        },
    });
    const {
        register,
        control,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'security-policy'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            saveSecurityPolicy(companyId, {
                lock_version: policy.version,
                two_factor_permissions:
                    values.two_factor_permissions as PermissionKey[],
                idle_timeout_minutes:
                    values.idle === '' ? null : Number(values.idle),
                client_ip_allowlist: lines(values.allowlist),
            }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(t('policy.saved', { version: saved.version }));
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                // The reload replaces the form with the newer version, so the notice cannot live inside it.
                toast.error(t('policy.conflict'));
                await refresh();

                return;
            }

            if (isApiError(error) && error.status === 422) {
                const fieldMap = Object.fromEntries(
                    Object.keys(error.errors).flatMap((apiField) => {
                        const field = fieldFor(apiField);

                        return field ? [[apiField, field]] : [];
                    }),
                );

                if (applyApiErrors(form.setError, error, fieldMap)) {
                    return;
                }
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
        <form
            noValidate
            className="max-w-3xl"
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                save.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <FieldSet
                    data-invalid={
                        errors.two_factor_permissions ? true : undefined
                    }
                >
                    <FieldLegend variant="label">
                        {t('policy.twoFactor.title')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('policy.twoFactor.description')}
                    </FieldDescription>
                    <Controller
                        control={control}
                        name="two_factor_permissions"
                        render={({ field }) => (
                            <PermissionChecklist
                                companyId={companyId}
                                idPrefix="policy-permission"
                                value={field.value}
                                onChange={field.onChange}
                            />
                        )}
                    />
                    {errors.two_factor_permissions && (
                        <FieldError>
                            {errors.two_factor_permissions.message}
                        </FieldError>
                    )}
                </FieldSet>

                <Field data-invalid={errors.idle ? true : undefined}>
                    <FieldLabel htmlFor="policy-idle">
                        {t('policy.idle.title')}
                    </FieldLabel>
                    <Input
                        id="policy-idle"
                        inputMode="numeric"
                        className="w-40 tabular-nums"
                        placeholder={t('policy.idle.never')}
                        aria-invalid={errors.idle ? true : undefined}
                        {...register('idle', {
                            validate: (value) =>
                                value === '' ||
                                (/^\d+$/.test(value) &&
                                    Number(value) >= 1 &&
                                    Number(value) <= MAX_IDLE_MINUTES) ||
                                t('policy.idle.invalid', {
                                    max: MAX_IDLE_MINUTES,
                                }),
                        })}
                    />
                    <FieldDescription>
                        {t('policy.idle.description', {
                            max: MAX_IDLE_MINUTES,
                        })}
                    </FieldDescription>
                    {errors.idle && (
                        <FieldError>{errors.idle.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.allowlist ? true : undefined}>
                    <FieldLabel htmlFor="policy-allowlist">
                        {t('policy.allowlist.title')}
                    </FieldLabel>
                    <Textarea
                        id="policy-allowlist"
                        rows={4}
                        className="font-mono text-xs"
                        placeholder={'203.0.113.10\n198.51.100.0/24'}
                        aria-invalid={errors.allowlist ? true : undefined}
                        {...register('allowlist')}
                    />
                    <FieldDescription>
                        {t('policy.allowlist.description')}
                    </FieldDescription>
                    {errors.allowlist && (
                        <FieldError>{errors.allowlist.message}</FieldError>
                    )}
                </Field>

                <div className="flex items-center gap-4">
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('common.save')}
                    </SubmitButton>
                    <span className="text-sm text-muted-foreground">
                        {policy.version === 0
                            ? t('policy.noVersion')
                            : t('policy.version', { version: policy.version })}
                    </span>
                </div>
            </FieldGroup>
        </form>
    );
}

/**
 * Security policy of the company (IA-FR-05): which permissions need 2FA, how long an idle session
 * lasts, and which addresses integration clients may call from. Every save is a new version.
 */
export function SecurityPolicyPage({ companyId }: { companyId: string }) {
    const { t } = useTranslation();

    useDocumentTitle(t('policy.pageTitle'));

    const policy = useQuery(securityPolicyQuery(companyId));

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('policy.title')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('policy.description')}
                </p>
            </div>

            {policy.isError ? (
                <FormAlert>{genericErrorMessage(policy.error, t)}</FormAlert>
            ) : policy.data === undefined ? (
                <Skeleton className="h-64 w-full max-w-3xl" />
            ) : (
                <PolicyForm
                    key={policy.data.version}
                    companyId={companyId}
                    policy={policy.data}
                />
            )}
        </>
    );
}
