import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { todayIso } from '@/lib/date';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    endMember,
    proposeMember,
    type PerimeterMember,
} from '@/modules/consolidation/groups';
import type { Workspace } from '@/modules/identity-access/workspaces';

type ProposeValues = {
    company_id: string;
    control_basis: string;
    effective_from: string;
};

function ProposeForm({
    groupId,
    companies,
    onDone,
}: {
    groupId: string;
    companies: Workspace[];
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<ProposeValues>({
        defaultValues: {
            company_id: companies.length === 1 ? companies[0].company_id : '',
            control_basis: '',
            effective_from: todayIso(),
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;
    const items = companies.map((company) => ({
        value: company.company_id,
        label: company.company_name,
    }));

    const propose = useMutation({
        mutationFn: (values: ProposeValues) =>
            proposeMember(groupId, {
                company_id: values.company_id,
                control_basis: values.control_basis.trim(),
                effective_from: values.effective_from,
            }),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'perimeter'],
            });
            toast.success(t('consolidation.perimeter.proposed'));
            onDone();
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
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                propose.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field data-invalid={errors.company_id ? true : undefined}>
                    <FieldLabel htmlFor="member-company">
                        {t('consolidation.perimeter.company')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="company_id"
                        rules={{
                            validate: (value) =>
                                value !== '' || t('common.required'),
                        }}
                        render={({ field }) => (
                            <SearchableSelect
                                id="member-company"
                                items={items}
                                value={field.value}
                                onValueChange={field.onChange}
                                emptyText={t('common.noMatch')}
                                aria-invalid={
                                    errors.company_id ? true : undefined
                                }
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('consolidation.perimeter.companyHint')}
                    </FieldDescription>
                    {errors.company_id && (
                        <FieldError>{errors.company_id.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.control_basis ? true : undefined}>
                    <FieldLabel htmlFor="member-basis">
                        {t('consolidation.perimeter.controlBasis')}
                    </FieldLabel>
                    <Textarea
                        id="member-basis"
                        rows={4}
                        aria-invalid={errors.control_basis ? true : undefined}
                        {...register('control_basis', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    <FieldDescription>
                        {t('consolidation.perimeter.controlBasisHint')}
                    </FieldDescription>
                    {errors.control_basis && (
                        <FieldError>{errors.control_basis.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.effective_from ? true : undefined}>
                    <FieldLabel id="member-from-label">
                        {t('consolidation.perimeter.effectiveFrom')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="effective_from"
                        rules={{
                            validate: (value) =>
                                value !== '' || t('common.required'),
                        }}
                        render={({ field }) => (
                            <DatePicker
                                labelledBy="member-from-label"
                                value={field.value}
                                onChange={field.onChange}
                            />
                        )}
                    />
                    {errors.effective_from && (
                        <FieldError>{errors.effective_from.message}</FieldError>
                    )}
                </Field>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        pending={propose.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('consolidation.perimeter.propose')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Proposes a company with the reasoning for the assessment of control; it counts once someone else approves it (CO-FR-02). */
export function ProposeMemberDialog({
    groupId,
    companies,
    open,
    onOpenChange,
}: {
    groupId: string;
    companies: Workspace[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('consolidation.perimeter.proposeTitle')}
            description={t('consolidation.perimeter.proposeDescription')}
        >
            <ProposeForm
                groupId={groupId}
                companies={companies}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}

function EndForm({
    groupId,
    member,
    onDone,
}: {
    groupId: string;
    member: PerimeterMember;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const [effectiveTo, setEffectiveTo] = useState(todayIso());

    const end = useMutation({
        mutationFn: () => endMember(groupId, member.id, effectiveTo),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'perimeter'],
            });
            toast.success(t('consolidation.perimeter.ended'));
            onDone();
        },
        onError: (error) => {
            setFormError(
                isApiError(error) && error.errors.effective_to?.[0]
                    ? error.errors.effective_to[0]
                    : actionErrorMessage(error, t),
            );
        },
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                end.mutate();
            }}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field>
                    <FieldLabel id="member-to-label">
                        {t('consolidation.perimeter.effectiveTo')}
                    </FieldLabel>
                    <DatePicker
                        labelledBy="member-to-label"
                        value={effectiveTo}
                        onChange={setEffectiveTo}
                    />
                    <FieldDescription>
                        {t('consolidation.perimeter.endHint')}
                    </FieldDescription>
                </Field>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        variant="destructive"
                        pending={end.isPending}
                        pendingLabel={t('common.saving')}
                        disabled={effectiveTo === ''}
                    >
                        {t('consolidation.perimeter.end')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** A member leaves the perimeter as of a date; its row stays, and runs for earlier ranges still include it. */
export function EndMemberDialog({
    groupId,
    member,
    onOpenChange,
}: {
    groupId: string;
    member: PerimeterMember | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={member !== null}
            onOpenChange={onOpenChange}
            title={t('consolidation.perimeter.endTitle', {
                name: member?.company_name ?? '',
            })}
            description={t('consolidation.perimeter.endDescription')}
        >
            {member && (
                <EndForm
                    groupId={groupId}
                    member={member}
                    onDone={() => onOpenChange(false)}
                />
            )}
        </FormDialog>
    );
}
