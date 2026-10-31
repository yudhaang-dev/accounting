import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, XIcon } from 'lucide-react';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
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
import { Label } from '@/components/ui/label';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createParty,
    updateParty,
    type Party,
} from '@/modules/subledger/parties';
import { partyRolesQuery } from '@/modules/subledger/party-roles';
import { postingProfilesQuery } from '@/modules/subledger/posting-profiles';

type Ref = { source_system: string; external_ref: string };

type Values = {
    name: string;
    roles: string[];
    tax_id: string;
    posting_profile_id: string;
    /** References to add; the ones the party already has are shown apart and never resent. */
    external_refs: Ref[];
};

const SOURCE_SYSTEM = /^[a-z0-9][a-z0-9_.-]{1,63}$/;

function PartyForm({
    companyId,
    party,
    canReadProfiles,
    onDone,
}: {
    companyId: string;
    party?: Party;
    canReadProfiles: boolean;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const profiles = useQuery({
        ...postingProfilesQuery(companyId),
        enabled: canReadProfiles,
    });
    const form = useForm<Values>({
        defaultValues: {
            name: party?.name ?? '',
            roles: party?.roles ?? [],
            tax_id: party?.tax_id ?? '',
            posting_profile_id: party?.posting_profile_id ?? '',
            external_refs: [],
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;
    const refs = useFieldArray({ control, name: 'external_refs' });
    const roles = useQuery(partyRolesQuery(companyId));
    // An inactive role stays listed while the party still holds it, so saving does not drop it silently.
    const roleChoices = (roles.data ?? [])
        .filter(
            (role) =>
                role.status === 'active' || party?.roles.includes(role.key),
        )
        .map((role) => ({ key: role.key, label: role.label }));

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'parties'],
        });

    const profileItems = [
        { value: '', label: t('parties.defaultProfile') },
        ...(profiles.data ?? []).map((profile) => ({
            value: profile.id,
            label: profile.name,
        })),
    ];

    const save = useMutation({
        mutationFn: (values: Values) => {
            const common = {
                name: values.name.trim(),
                roles: values.roles,
                tax_id:
                    values.tax_id.trim() === '' ? null : values.tax_id.trim(),
                external_refs: values.external_refs.map((ref) => ({
                    source_system: ref.source_system.trim(),
                    external_ref: ref.external_ref.trim(),
                })),
            };
            // Without the profile list the select is not offered, so the profile is left as it is.
            const profile = canReadProfiles
                ? { posting_profile_id: values.posting_profile_id || null }
                : {};

            return party
                ? updateParty(companyId, party.id, {
                      lock_version: party.lock_version,
                      ...common,
                      ...profile,
                  })
                : createParty(companyId, { ...common, ...profile });
        },
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(party ? 'parties.saved' : 'parties.created', {
                    name: saved.name,
                }),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('parties.conflict'));

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

                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="party-name">
                        {t('parties.name')}
                    </FieldLabel>
                    <Input
                        id="party-name"
                        autoFocus
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

                <FieldSet>
                    <FieldLegend variant="label">
                        {t('parties.roles')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('parties.rolesHint')}
                    </FieldDescription>
                    <Controller
                        control={control}
                        name="roles"
                        render={({ field }) => (
                            <div className="flex flex-wrap gap-x-6 gap-y-2">
                                {roleChoices.map((choice) => (
                                    <div
                                        key={choice.key}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`party-role-${choice.key}`}
                                            checked={field.value.includes(
                                                choice.key,
                                            )}
                                            onCheckedChange={(checked) =>
                                                field.onChange(
                                                    checked === true
                                                        ? [
                                                              ...field.value,
                                                              choice.key,
                                                          ]
                                                        : field.value.filter(
                                                              (key) =>
                                                                  key !==
                                                                  choice.key,
                                                          ),
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={`party-role-${choice.key}`}
                                            className="font-normal"
                                        >
                                            {choice.label}
                                        </Label>
                                    </div>
                                ))}
                                {roleChoices.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('parties.noRoles')}
                                    </p>
                                )}
                            </div>
                        )}
                    />
                    {errors.roles && (
                        <FieldError>{errors.roles.message}</FieldError>
                    )}
                </FieldSet>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={errors.tax_id ? true : undefined}>
                        <FieldLabel htmlFor="party-tax-id">
                            {t('parties.taxId')}
                        </FieldLabel>
                        <Input
                            id="party-tax-id"
                            aria-invalid={errors.tax_id ? true : undefined}
                            {...register('tax_id', {
                                maxLength: {
                                    value: 64,
                                    message: t('parties.taxIdInvalid'),
                                },
                            })}
                        />
                        {errors.tax_id && (
                            <FieldError>{errors.tax_id.message}</FieldError>
                        )}
                    </Field>
                    {canReadProfiles && (
                        <Field
                            data-invalid={
                                errors.posting_profile_id ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="party-profile">
                                {t('parties.profile')}
                            </FieldLabel>
                            <Controller
                                control={control}
                                name="posting_profile_id"
                                render={({ field }) => (
                                    <SearchableSelect
                                        id="party-profile"
                                        items={profileItems}
                                        value={field.value}
                                        onValueChange={field.onChange}
                                        emptyText={t('common.noMatch')}
                                        placeholder={t(
                                            'parties.defaultProfile',
                                        )}
                                        aria-invalid={
                                            errors.posting_profile_id
                                                ? true
                                                : undefined
                                        }
                                    />
                                )}
                            />
                            <FieldDescription>
                                {t('parties.profileHint')}
                            </FieldDescription>
                            {errors.posting_profile_id && (
                                <FieldError>
                                    {errors.posting_profile_id.message}
                                </FieldError>
                            )}
                        </Field>
                    )}
                </div>

                <FieldSet>
                    <FieldLegend variant="label">
                        {t('parties.refs.title')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('parties.refs.hint')}
                    </FieldDescription>

                    {party && party.external_refs.length > 0 && (
                        <ul
                            className="flex flex-col gap-1 text-sm"
                            aria-label={t('parties.refs.existing')}
                        >
                            {party.external_refs.map((ref) => (
                                <li
                                    key={`${ref.source_system}/${ref.external_ref}`}
                                    className="flex flex-wrap gap-x-2"
                                >
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {ref.source_system}
                                    </span>
                                    <span className="font-mono text-xs">
                                        {ref.external_ref}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    {refs.fields.map((item, index) => {
                        const fieldErrors = errors.external_refs?.[index];

                        return (
                            <div
                                key={item.id}
                                className="grid grid-cols-[1fr_1fr_auto] items-start gap-2"
                            >
                                <Field
                                    data-invalid={
                                        fieldErrors?.source_system
                                            ? true
                                            : undefined
                                    }
                                >
                                    <Input
                                        aria-label={t(
                                            'parties.refs.sourceSystem',
                                            { n: index + 1 },
                                        )}
                                        placeholder={t(
                                            'parties.refs.sourceSystemPlaceholder',
                                        )}
                                        className="font-mono"
                                        aria-invalid={
                                            fieldErrors?.source_system
                                                ? true
                                                : undefined
                                        }
                                        {...register(
                                            `external_refs.${index}.source_system`,
                                            {
                                                validate: (value) =>
                                                    SOURCE_SYSTEM.test(
                                                        value.trim(),
                                                    ) ||
                                                    t(
                                                        'parties.refs.sourceSystemInvalid',
                                                    ),
                                            },
                                        )}
                                    />
                                    {fieldErrors?.source_system && (
                                        <FieldError>
                                            {fieldErrors.source_system.message}
                                        </FieldError>
                                    )}
                                </Field>
                                <Field
                                    data-invalid={
                                        fieldErrors?.external_ref
                                            ? true
                                            : undefined
                                    }
                                >
                                    <Input
                                        aria-label={t(
                                            'parties.refs.externalRef',
                                            { n: index + 1 },
                                        )}
                                        placeholder={t(
                                            'parties.refs.externalRefPlaceholder',
                                        )}
                                        className="font-mono"
                                        aria-invalid={
                                            fieldErrors?.external_ref
                                                ? true
                                                : undefined
                                        }
                                        {...register(
                                            `external_refs.${index}.external_ref`,
                                            {
                                                validate: (value) =>
                                                    (value.trim() !== '' &&
                                                        value.trim().length <=
                                                            128) ||
                                                    t(
                                                        'parties.refs.externalRefInvalid',
                                                    ),
                                            },
                                        )}
                                    />
                                    {fieldErrors?.external_ref && (
                                        <FieldError>
                                            {fieldErrors.external_ref.message}
                                        </FieldError>
                                    )}
                                </Field>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('parties.refs.remove', {
                                        n: index + 1,
                                    })}
                                    onClick={() => refs.remove(index)}
                                >
                                    <XIcon />
                                </Button>
                            </div>
                        );
                    })}

                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                refs.append({
                                    source_system: '',
                                    external_ref: '',
                                })
                            }
                        >
                            <PlusIcon data-icon="inline-start" />
                            {t('parties.refs.add')}
                        </Button>
                    </div>
                </FieldSet>

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

/** Adds a party, or edits one: roles, tax id, posting profile, and the references source systems use (SL-FR-01). */
export function PartyDialog({
    companyId,
    party,
    canReadProfiles,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The party being edited; none means a new party. */
    party?: Party;
    /** Whether the user may list posting profiles, which the profile select needs. */
    canReadProfiles: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title={
                party
                    ? t('parties.editTitle', { name: party.name })
                    : t('parties.createTitle')
            }
            description={t('parties.description')}
        >
            <PartyForm
                key={party?.id ?? 'new'}
                companyId={companyId}
                party={party}
                canReadProfiles={canReadProfiles}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
