import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Skeleton } from '@/components/ui/skeleton';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createPartyRole,
    importPartyRoles,
    partyRoleTemplatesQuery,
    updatePartyRole,
    type PartyRole,
} from '@/modules/subledger/party-roles';

const KEY = /^[a-z][a-z0-9_]{1,31}$/;

type Values = { key: string; label: string };

function RoleForm({
    companyId,
    role,
    onDone,
}: {
    companyId: string;
    role?: PartyRole;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: { key: role?.key ?? '', label: role?.label ?? '' },
    });
    const {
        register,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'party-roles'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            role
                ? updatePartyRole(companyId, role.id, {
                      lock_version: role.lock_version,
                      label: values.label.trim(),
                  })
                : createPartyRole(companyId, {
                      key: values.key.trim(),
                      label: values.label.trim(),
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(role ? 'partyRoles.saved' : 'partyRoles.created', {
                    name: saved.label,
                }),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('partyRoles.conflict'));

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
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                save.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field data-invalid={errors.label ? true : undefined}>
                    <FieldLabel htmlFor="party-role-label">
                        {t('partyRoles.label')}
                    </FieldLabel>
                    <Input
                        id="party-role-label"
                        autoFocus
                        aria-invalid={errors.label ? true : undefined}
                        {...register('label', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.label && (
                        <FieldError>{errors.label.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.key ? true : undefined}>
                    <FieldLabel htmlFor="party-role-key">
                        {t('partyRoles.key')}
                    </FieldLabel>
                    <Input
                        id="party-role-key"
                        className="font-mono"
                        disabled={role !== undefined}
                        aria-invalid={errors.key ? true : undefined}
                        {...register('key', {
                            validate: (value) =>
                                role !== undefined ||
                                KEY.test(value.trim()) ||
                                t('partyRoles.keyInvalid'),
                        })}
                    />
                    <FieldDescription>
                        {t(
                            role
                                ? 'partyRoles.keyLocked'
                                : 'partyRoles.keyHint',
                        )}
                    </FieldDescription>
                    {errors.key && (
                        <FieldError>{errors.key.message}</FieldError>
                    )}
                </Field>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('common.save')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Adds a party role, or renames one; the key is fixed once the role exists (SL-FR-01). */
export function PartyRoleDialog({
    companyId,
    role,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The role being edited; none means a new role. */
    role?: PartyRole;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                role
                    ? t('partyRoles.editTitle', { name: role.label })
                    : t('partyRoles.createTitle')
            }
            description={t('partyRoles.description')}
        >
            <RoleForm
                key={role?.id ?? 'new'}
                companyId={companyId}
                role={role}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}

function ImportForm({
    companyId,
    existingKeys,
    onDone,
}: {
    companyId: string;
    existingKeys: Set<string>;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const templates = useQuery(partyRoleTemplatesQuery(companyId, true));
    const [unpicked, setUnpicked] = useState<Set<string>>(new Set());
    const [confirmed, setConfirmed] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const chosen = (templates.data ?? []).filter(
        (template) =>
            !existingKeys.has(template.key) && !unpicked.has(template.key),
    );

    const run = useMutation({
        mutationFn: () =>
            importPartyRoles(
                companyId,
                chosen.map((template) => template.key),
            ),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'party-roles'],
            });
            toast.success(t('partyRoles.import.done'));
            onDone();
        },
        onError: (error) => {
            if (isApiError(error) && error.status === 422) {
                const messages = Object.values(error.errors).flat();

                setFormError(
                    messages.length > 0 ? messages.join(' ') : error.message,
                );

                return;
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    if (templates.isError) {
        return <FormAlert>{genericErrorMessage(templates.error, t)}</FormAlert>;
    }

    if (templates.data === undefined) {
        return <Skeleton className="h-40 w-full" />;
    }

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                run.mutate();
            }}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}
                <p className="text-sm text-muted-foreground">
                    {t('partyRoles.import.intro')}
                </p>

                <FieldSet>
                    <FieldLegend variant="label">
                        {t('partyRoles.import.review')}
                    </FieldLegend>
                    <ul className="flex flex-col gap-2">
                        {templates.data.map((template) => {
                            const taken = existingKeys.has(template.key);
                            const id = `import-role-${template.key}`;

                            return (
                                <li
                                    key={template.key}
                                    className="rounded-lg border p-3"
                                >
                                    <Field orientation="horizontal">
                                        <Checkbox
                                            id={id}
                                            checked={
                                                !taken &&
                                                !unpicked.has(template.key)
                                            }
                                            disabled={taken}
                                            onCheckedChange={(on) =>
                                                setUnpicked((current) => {
                                                    const next = new Set(
                                                        current,
                                                    );

                                                    if (on === true) {
                                                        next.delete(
                                                            template.key,
                                                        );
                                                    } else {
                                                        next.add(template.key);
                                                    }

                                                    return next;
                                                })
                                            }
                                        />
                                        <div className="flex flex-1 flex-wrap items-center gap-2">
                                            <FieldLabel htmlFor={id}>
                                                {template.label}
                                            </FieldLabel>
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {template.key}
                                            </span>
                                            {taken && (
                                                <Badge variant="secondary">
                                                    {t(
                                                        'partyRoles.import.taken',
                                                    )}
                                                </Badge>
                                            )}
                                        </div>
                                    </Field>
                                </li>
                            );
                        })}
                    </ul>
                </FieldSet>

                <Field orientation="horizontal">
                    <Checkbox
                        id="import-roles-confirmed"
                        checked={confirmed}
                        onCheckedChange={(next) => setConfirmed(next === true)}
                    />
                    <FieldLabel
                        htmlFor="import-roles-confirmed"
                        className="font-normal"
                    >
                        {t('partyRoles.import.confirm')}
                    </FieldLabel>
                </Field>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        disabled={!confirmed || chosen.length === 0}
                        pending={run.isPending}
                        pendingLabel={t('partyRoles.import.working')}
                    >
                        {t('partyRoles.import.action', {
                            count: chosen.length,
                        })}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/**
 * Brings in the party roles the template suggests (SL-FR-01). The user reads the roles, picks the
 * ones they want and confirms before anything is created (X-AC-03).
 */
export function PartyRoleImportDialog({
    companyId,
    existingKeys,
    open,
    onOpenChange,
}: {
    companyId: string;
    existingKeys: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('partyRoles.import.title')}
            description={t('partyRoles.import.intro')}
        >
            <ImportForm
                companyId={companyId}
                existingKeys={new Set(existingKeys)}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
