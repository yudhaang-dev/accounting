import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
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
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createVersion,
    renameVersion,
    reviseVersion,
    type PlanVersion,
} from '@/modules/planning/plans';

/** What the name is for: a new empty draft of a plan, a draft copied from a published version, or a draft's new name. */
export type VersionDialogMode =
    | { kind: 'create'; planId: string }
    | { kind: 'revise'; version: PlanVersion }
    | { kind: 'rename'; version: PlanVersion };

type Values = { name: string };

function VersionForm({
    companyId,
    mode,
    onDone,
}: {
    companyId: string;
    mode: VersionDialogMode;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            name: mode.kind === 'rename' ? mode.version.name : '',
        },
    });
    const {
        register,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'plans'],
        });

    const save = useMutation({
        mutationFn: ({ name }: Values) => {
            const trimmed = name.trim();

            switch (mode.kind) {
                case 'create':
                    return createVersion(companyId, mode.planId, trimmed);
                case 'revise':
                    return reviseVersion(companyId, mode.version.id, trimmed);
                case 'rename':
                    return renameVersion(companyId, mode.version.id, {
                        lock_version: mode.version.lock_version,
                        name: trimmed,
                    });
            }
        },
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(`plans.versionDialog.${mode.kind}Done`, { name: saved.name }),
            );
            onDone();

            // A new draft is where the work goes next.
            if (mode.kind !== 'rename') {
                await navigate({
                    to: '/w/$companyId/plan-versions/$versionId',
                    params: { companyId, versionId: saved.id },
                });
            }
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('plans.versionDialog.conflict'));

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
                    <FieldLabel htmlFor="version-name">
                        {t('plans.versionDialog.name')}
                    </FieldLabel>
                    <Input
                        id="version-name"
                        autoFocus
                        aria-invalid={errors.name ? true : undefined}
                        {...register('name', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    <FieldDescription>
                        {t(`plans.versionDialog.${mode.kind}Hint`)}
                    </FieldDescription>
                    {errors.name && (
                        <FieldError>{errors.name.message}</FieldError>
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
                        {t(`plans.versionDialog.${mode.kind}Submit`)}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Names a version: a new draft, a revision of a published version (PL-AC-01), or a rename of a draft. */
export function VersionDialog({
    companyId,
    mode,
    open,
    onOpenChange,
}: {
    companyId: string;
    mode: VersionDialogMode | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open && mode !== null}
            onOpenChange={onOpenChange}
            title={mode ? t(`plans.versionDialog.${mode.kind}Title`) : ''}
            description={t('plans.versionDialog.description')}
        >
            {mode && (
                <VersionForm
                    key={`${mode.kind}-${'planId' in mode ? mode.planId : mode.version.id}`}
                    companyId={companyId}
                    mode={mode}
                    onDone={() => onOpenChange(false)}
                />
            )}
        </FormDialog>
    );
}
