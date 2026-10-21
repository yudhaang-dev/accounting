import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
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
import { Textarea } from '@/components/ui/textarea';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createIntegrationClient,
    INTEGRATION_SCOPES,
    updateIntegrationClient,
    type IntegrationClient,
    type IntegrationClientWithSecret,
    type IntegrationScope,
} from '@/modules/identity-access/access';
import {
    CheckItem,
    toggled,
} from '@/modules/identity-access/components/check-item';
import { DataScopeField } from '@/modules/identity-access/components/data-scope-field';

/** The scopes are OAuth identifiers (with colons), which i18next would read as namespaces: map them to plain keys. */
export const SCOPE_LABELS = {
    'transaction-types:read': 'transactionTypesRead',
    'financial-events:write': 'financialEventsWrite',
    'financial-events:read:self': 'financialEventsReadSelf',
    'exchange-rates:write': 'exchangeRatesWrite',
} as const satisfies Record<IntegrationScope, string>;

type Values = {
    name: string;
    source_system: string;
    scopes: IntegrationScope[];
    /** Keys separated by spaces, commas or new lines. */
    type_keys: string;
    data_scope: string[];
};

const SOURCE_SYSTEM = /^[a-z0-9][a-z0-9_.-]{1,63}$/;
const TYPE_KEY = /^[a-z0-9][a-z0-9_.-]{0,63}$/;

const keys = (text: string): string[] => [
    ...new Set(text.split(/[\s,]+/).filter(Boolean)),
];

const sameSet = (a: string[], b: string[]): boolean =>
    a.length === b.length && a.every((id) => b.includes(id));

function ClientForm({
    companyId,
    client,
    canReadDimensions,
    onCreated,
    onDone,
}: {
    companyId: string;
    client?: IntegrationClient;
    canReadDimensions: boolean;
    onCreated: (client: IntegrationClientWithSecret) => void;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const initialScope = client?.data_scope ?? [];
    const form = useForm<Values>({
        defaultValues: {
            name: client?.name ?? '',
            source_system: client?.source_system ?? '',
            scopes: (client?.scopes ?? []) as IntegrationScope[],
            type_keys: (client?.transaction_type_keys ?? []).join('\n'),
            data_scope: initialScope,
        },
    });
    const {
        register,
        control,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'integration-clients'],
        });

    const save = useMutation({
        mutationFn: async (values: Values) => {
            const body = {
                name: values.name.trim(),
                scopes: values.scopes,
                transaction_type_keys: keys(values.type_keys),
            };

            if (client) {
                await updateIntegrationClient(companyId, client.id, {
                    ...body,
                    lock_version: client.lock_version,
                    // Only a change is sent: a user who is limited themselves may not set a scope at all.
                    ...(canReadDimensions &&
                        !sameSet(values.data_scope, initialScope) && {
                            data_scope: values.data_scope,
                        }),
                });

                return null;
            }

            return createIntegrationClient(companyId, {
                ...body,
                source_system: values.source_system.trim(),
                ...(values.data_scope.length > 0 && {
                    data_scope: values.data_scope,
                }),
            });
        },
        onSuccess: async (created) => {
            await refresh();

            if (created) {
                onCreated(created);
            } else {
                toast.success(t('integrations.form.saved'));
            }

            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('integrations.form.conflict'));

                return;
            }

            if (isApiError(error) && error.status === 422) {
                const fieldMap: Record<string, keyof Values> = {};

                for (const apiField of Object.keys(error.errors)) {
                    if (apiField.startsWith('transaction_type_keys')) {
                        fieldMap[apiField] = 'type_keys';
                    } else if (apiField.startsWith('scopes')) {
                        fieldMap[apiField] = 'scopes';
                    } else if (apiField.startsWith('data_scope')) {
                        fieldMap[apiField] = 'data_scope';
                    }
                }

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
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);

                save.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="integration-name">
                        {t('integrations.form.name')}
                    </FieldLabel>
                    <Input
                        id="integration-name"
                        maxLength={100}
                        aria-invalid={errors.name ? true : undefined}
                        {...register('name', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.name && (
                        <FieldError>{errors.name.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.source_system ? true : undefined}>
                    <FieldLabel htmlFor="integration-source">
                        {t('integrations.form.sourceSystem')}
                    </FieldLabel>
                    <Input
                        id="integration-source"
                        className="font-mono text-xs"
                        disabled={client !== undefined}
                        aria-invalid={errors.source_system ? true : undefined}
                        {...register('source_system', {
                            validate: (value) =>
                                client !== undefined ||
                                SOURCE_SYSTEM.test(value.trim()) ||
                                t('integrations.form.sourceSystemInvalid'),
                        })}
                    />
                    <FieldDescription>
                        {t('integrations.form.sourceSystemHint')}
                    </FieldDescription>
                    {errors.source_system && (
                        <FieldError>{errors.source_system.message}</FieldError>
                    )}
                </Field>

                <FieldSet data-invalid={errors.scopes ? true : undefined}>
                    <FieldLegend variant="label">
                        {t('integrations.form.scopes')}
                    </FieldLegend>
                    <Controller
                        control={control}
                        name="scopes"
                        rules={{
                            validate: (value) =>
                                value.length > 0 ||
                                t('integrations.form.scopesRequired'),
                        }}
                        render={({ field }) => (
                            <div className="flex flex-col gap-2">
                                {INTEGRATION_SCOPES.map((scope) => (
                                    <CheckItem
                                        key={scope}
                                        id={`integration-scope-${scope}`}
                                        label={
                                            <span className="font-mono text-xs">
                                                {scope}
                                            </span>
                                        }
                                        description={t(
                                            `integrations.scopes.${SCOPE_LABELS[scope]}`,
                                        )}
                                        checked={field.value.includes(scope)}
                                        onChange={(on) => {
                                            field.onChange(
                                                toggled(field.value, scope, on),
                                            );
                                        }}
                                    />
                                ))}
                            </div>
                        )}
                    />
                    {errors.scopes && (
                        <FieldError>{errors.scopes.message}</FieldError>
                    )}
                </FieldSet>

                <Field data-invalid={errors.type_keys ? true : undefined}>
                    <FieldLabel htmlFor="integration-types">
                        {t('integrations.form.typeKeys')}
                    </FieldLabel>
                    <Textarea
                        id="integration-types"
                        rows={3}
                        className="font-mono text-xs"
                        aria-invalid={errors.type_keys ? true : undefined}
                        {...register('type_keys', {
                            validate: (value) =>
                                keys(value).every((key) =>
                                    TYPE_KEY.test(key),
                                ) || t('integrations.form.typeKeysInvalid'),
                        })}
                    />
                    <FieldDescription>
                        {t('integrations.form.typeKeysHint')}
                    </FieldDescription>
                    {errors.type_keys && (
                        <FieldError>{errors.type_keys.message}</FieldError>
                    )}
                </Field>

                {canReadDimensions && (
                    <FieldSet
                        data-invalid={errors.data_scope ? true : undefined}
                    >
                        <FieldLegend variant="label">
                            {t('integrations.scope.title')}
                        </FieldLegend>
                        <FieldDescription>
                            {t('integrations.scope.description')}
                        </FieldDescription>
                        <Controller
                            control={control}
                            name="data_scope"
                            render={({ field }) => (
                                <DataScopeField
                                    companyId={companyId}
                                    value={field.value}
                                    onChange={field.onChange}
                                />
                            )}
                        />
                        {errors.data_scope && (
                            <FieldError>{errors.data_scope.message}</FieldError>
                        )}
                    </FieldSet>
                )}

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {client
                            ? t('common.save')
                            : t('integrations.form.register')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/**
 * Registers an integration client or edits one (IA-FR-06): its scopes and the transaction types it may
 * use. The `source_system` is fixed once registered. A new client hands its secret to `onCreated`,
 * which is the only moment the secret exists in readable form.
 */
export function IntegrationClientDialog({
    companyId,
    client,
    canReadDimensions,
    open,
    onOpenChange,
    onCreated,
}: {
    companyId: string;
    /** Whether the user may read dimensions, which the data scope picker needs. */
    canReadDimensions: boolean;
    /** The client being edited; none means registering a new one. */
    client?: IntegrationClient;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (client: IntegrationClientWithSecret) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title={
                client
                    ? t('integrations.form.editTitle', { name: client.name })
                    : t('integrations.form.registerTitle')
            }
            description={t('integrations.description')}
        >
            <ClientForm
                key={client?.id ?? 'new'}
                companyId={companyId}
                client={client}
                canReadDimensions={canReadDimensions}
                onCreated={onCreated}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
