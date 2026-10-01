import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { FieldGroup } from '@/components/ui/field';
import { genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    CompanyFields,
    type CompanyFormValues,
} from '@/modules/master-data/components/company-fields';
import {
    companyQuery,
    updateCompany,
    type Company,
} from '@/modules/master-data/company';

type Props = {
    company: Company;
    onSaved: () => void;
    onCancel: () => void;
};

const valuesOf = (company: Company): CompanyFormValues => ({
    name: company.name,
    legal_name: company.legal_name ?? '',
    tax_id: company.tax_id ?? '',
    functional_currency: company.functional_currency,
    timezone: company.timezone,
    default_locale: company.default_locale === 'en' ? 'en' : 'id',
});

/** Edits the company profile (MD-FR-01). A stale `lock_version` reloads the data and asks again. */
export function CompanyProfileForm({ company, onSaved, onCancel }: Props) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<CompanyFormValues>({
        defaultValues: valuesOf(company),
    });

    const save = useMutation({
        mutationFn: (values: CompanyFormValues) =>
            updateCompany(company.id, {
                lock_version: company.lock_version,
                name: values.name,
                legal_name: values.legal_name === '' ? null : values.legal_name,
                tax_id: values.tax_id === '' ? null : values.tax_id,
                // Sent only while it can still change; the API refuses it afterwards (MD-AC-05).
                ...(company.status === 'setup'
                    ? { functional_currency: values.functional_currency }
                    : {}),
                timezone: values.timezone,
                default_locale: values.default_locale,
            }),
        onSuccess: async (saved) => {
            queryClient.setQueryData(companyQuery(company.id).queryKey, saved);
            onSaved();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                const latest = await queryClient.fetchQuery({
                    ...companyQuery(company.id),
                    staleTime: 0,
                });

                form.reset(valuesOf(latest));
                setFormError(t('setup.profile.conflict'));

                return;
            }

            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error)
            ) {
                return;
            }

            setFormError(genericErrorMessage(error, t));
        },
    });

    return (
        <FormProvider {...form}>
            <form
                noValidate
                onSubmit={form.handleSubmit((values) => {
                    setFormError(null);
                    save.mutate(values);
                })}
            >
                <FieldGroup>
                    {formError && <FormAlert>{formError}</FormAlert>}
                    <CompanyFields
                        withLegalIdentity
                        currencyLocked={company.status !== 'setup'}
                    />
                    <div className="flex flex-wrap gap-2">
                        <SubmitButton
                            className="w-auto"
                            pending={save.isPending}
                            pendingLabel={t('setup.profile.saving')}
                        >
                            {t('setup.profile.save')}
                        </SubmitButton>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onCancel}
                        >
                            {t('setup.close')}
                        </Button>
                    </div>
                </FieldGroup>
            </form>
        </FormProvider>
    );
}
