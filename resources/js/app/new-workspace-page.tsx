import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
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
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createWorkspace,
    workspacesQuery,
} from '@/modules/identity-access/workspaces';
import {
    CompanyFields,
    type CompanyFormValues,
} from '@/modules/master-data/components/company-fields';

type Values = CompanyFormValues & { workspace_name: string };

/**
 * Creates a workspace together with its company (IA-FR-01, MD-FR-01). The company starts in
 * `setup`; the wizard that follows installs its chart of accounts and calendar before activation.
 */
export function NewWorkspacePage() {
    const { t, i18n } = useTranslation();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);

    useDocumentTitle(t('newWorkspace.pageTitle'));

    const form = useForm<Values>({
        defaultValues: {
            workspace_name: '',
            name: '',
            legal_name: '',
            tax_id: '',
            functional_currency: 'IDR',
            timezone: 'Asia/Jakarta',
            default_locale: i18n.language === 'en' ? 'en' : 'id',
        },
    });
    const {
        register,
        formState: { errors },
    } = form;

    const create = useMutation({
        mutationFn: (values: Values) =>
            createWorkspace({
                workspace_name: values.workspace_name,
                company_name: values.name,
                functional_currency: values.functional_currency,
                timezone: values.timezone,
                default_locale: values.default_locale,
            }),
        onSuccess: async (created) => {
            await queryClient.fetchQuery({ ...workspacesQuery, staleTime: 0 });
            await navigate({
                to: '/w/$companyId/setup',
                params: { companyId: created.company_id },
            });
        },
        onError: (error) => {
            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error, { company_name: 'name' })
            ) {
                return;
            }

            setFormError(genericErrorMessage(error, t));
        },
    });

    return (
        <Card className="max-w-xl">
            <CardHeader>
                <CardTitle role="heading" aria-level={1}>
                    {t('newWorkspace.title')}
                </CardTitle>
                <CardDescription>
                    {t('newWorkspace.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <FormProvider {...form}>
                    <form
                        noValidate
                        onSubmit={form.handleSubmit((values) => {
                            setFormError(null);
                            create.mutate(values);
                        })}
                    >
                        <FieldGroup>
                            {formError && <FormAlert>{formError}</FormAlert>}

                            <Field
                                data-invalid={
                                    errors.workspace_name ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="workspace-name">
                                    {t('newWorkspace.workspaceName')}
                                </FieldLabel>
                                <Input
                                    id="workspace-name"
                                    autoFocus
                                    aria-invalid={
                                        errors.workspace_name ? true : undefined
                                    }
                                    {...register('workspace_name', {
                                        required: t('common.required'),
                                    })}
                                />
                                {errors.workspace_name && (
                                    <FieldError>
                                        {errors.workspace_name.message}
                                    </FieldError>
                                )}
                            </Field>

                            <FieldSet>
                                <FieldLegend>
                                    {t('newWorkspace.companyDetails')}
                                </FieldLegend>
                                <FieldGroup>
                                    <CompanyFields />
                                </FieldGroup>
                            </FieldSet>

                            <div className="flex flex-wrap gap-2">
                                <SubmitButton
                                    className="w-auto"
                                    pending={create.isPending}
                                    pendingLabel={t('newWorkspace.submitting')}
                                >
                                    {t('newWorkspace.submit')}
                                </SubmitButton>
                                <Button
                                    variant="ghost"
                                    render={<Link to="/workspaces" />}
                                >
                                    {t('newWorkspace.cancel')}
                                </Button>
                            </div>
                        </FieldGroup>
                    </form>
                </FormProvider>
            </CardContent>
        </Card>
    );
}
