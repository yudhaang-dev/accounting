import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import {
    ArrowDownIcon,
    ArrowUpIcon,
    PlusIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';
import {
    Controller,
    useFieldArray,
    useForm,
    useWatch,
    type Control,
    type FieldValues,
} from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { allAccountsQuery } from '@/modules/general-ledger/accounts';
import {
    blankClassification,
    blankComponent,
    blankRule,
    createClassification,
    toForm,
    updateClassification,
    type ClassificationForm,
} from '@/modules/reporting/classification-form';
import {
    classificationQuery,
    type MovementClassification,
} from '@/modules/reporting/classifications';
import { SelectorList } from '@/modules/reporting/components/selector-list';
import { formErrors } from '@/modules/reporting/definition-form';
import { OptionSelect } from '@/modules/transaction-entry/components/option-select';

type Errors = Record<string, string>;
type Register = ReturnType<typeof useForm<ClassificationForm>>['register'];

/** Loads the classification to change, or opens an empty form for a new one. */
export function ClassificationEditorPage({
    companyId,
    classificationId,
}: {
    companyId: string;
    classificationId?: string;
}) {
    const { t } = useTranslation();
    const classification = useQuery({
        ...classificationQuery(companyId, classificationId ?? ''),
        enabled: classificationId !== undefined,
    });

    useDocumentTitle(
        classificationId
            ? t('classificationEditor.editTitle')
            : t('classificationEditor.newTitle'),
    );

    if (classificationId === undefined) {
        return <Editor companyId={companyId} />;
    }

    if (classification.isError) {
        return (
            <FormAlert>
                {genericErrorMessage(classification.error, t)}
            </FormAlert>
        );
    }

    if (classification.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    return (
        <Editor companyId={companyId} classification={classification.data} />
    );
}

function Editor({
    companyId,
    classification,
}: {
    companyId: string;
    classification?: MovementClassification;
}) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [errors, setErrors] = useState<Errors>({});
    const [formError, setFormError] = useState<string | null>(null);
    const { control, register, handleSubmit } = useForm<ClassificationForm>({
        defaultValues: classification
            ? toForm(classification)
            : blankClassification(),
    });
    const rows = useFieldArray({ control, name: 'rows' });
    const rules = useFieldArray({ control, name: 'rules' });
    const components = useFieldArray({ control, name: 'components' });
    const useComponents = useWatch({ control, name: 'useComponents' });
    const rowKeys = useWatch({ control, name: 'rows' })
        .map((row) => row.key)
        .filter(Boolean);
    const accounts = useQuery(allAccountsQuery(companyId));
    const editing = classification !== undefined;
    const accountItems = (accounts.data ?? []).map((account) => ({
        value: account.id,
        label: `${account.code} · ${account.name}`,
    }));

    const save = useMutation({
        mutationFn: (form: ClassificationForm) =>
            classification
                ? updateClassification(companyId, classification, form)
                : createClassification(companyId, form),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: [
                    'companies',
                    companyId,
                    'movement-classifications',
                ],
            });
            toast.success(t('classificationEditor.saved'));
            await navigate({
                to: '/w/$companyId/reports',
                params: { companyId },
            });
        },
        onError: async (error) => {
            if (isApiError(error) && error.status === 422) {
                setErrors(formErrors(error.errors));
                setFormError(t('classificationEditor.invalid'));

                return;
            }

            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await queryClient.invalidateQueries({
                    queryKey: [
                        'companies',
                        companyId,
                        'movement-classifications',
                    ],
                });
                setFormError(t('classificationEditor.conflict'));

                return;
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    const submit = handleSubmit((form) => {
        setErrors({});
        setFormError(null);
        save.mutate(form);
    });

    const err = (path: string) =>
        errors[path] ? <FieldError>{errors[path]}</FieldError> : null;
    // A message for a path the form has no field for (the number of rows) still needs to be read.
    const orphans = Object.entries(errors).filter(
        ([path]) =>
            !/\.(key|label|row|accounts|transaction_type_keys)(\.|$)/.test(
                path,
            ),
    );

    return (
        <form
            noValidate
            onSubmit={submit}
            className="flex max-w-4xl flex-col gap-6"
        >
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {editing
                        ? t('classificationEditor.editTitle')
                        : t('classificationEditor.newTitle')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {editing
                        ? t('classificationEditor.editDescription', {
                              version: classification.current_version,
                          })
                        : t('classificationEditor.newDescription')}
                </p>
            </div>

            {formError && <FormAlert>{formError}</FormAlert>}
            {orphans.length > 0 && (
                <FormAlert>
                    {orphans.map(([, message]) => message).join(' ')}
                </FormAlert>
            )}

            <section className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={errors.key ? true : undefined}>
                    <FieldLabel htmlFor="cls-key">
                        {t('definitionEditor.key')}
                    </FieldLabel>
                    <Input
                        id="cls-key"
                        disabled={editing}
                        {...register('key')}
                    />
                    {err('key')}
                </Field>
                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="cls-name">
                        {t('definitionEditor.name')}
                    </FieldLabel>
                    <Input id="cls-name" {...register('name')} />
                    {err('name')}
                </Field>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">
                    {t('classificationEditor.rows')}
                </h2>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('classificationEditor.rowsHint')}
                </p>
                {rows.fields.map((row, index) => (
                    <div
                        key={row.id}
                        role="group"
                        aria-label={t('definitionEditor.rowN', {
                            n: index + 1,
                        })}
                        className="flex flex-wrap items-start gap-3 rounded-lg border p-3"
                    >
                        <Field
                            className="w-48"
                            data-invalid={
                                errors[`rows.${index}.key`] ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor={`crow-${index}-key`}>
                                {t('definitionEditor.rowKey', { n: index + 1 })}
                            </FieldLabel>
                            <Input
                                id={`crow-${index}-key`}
                                {...register(`rows.${index}.key`)}
                            />
                            {err(`rows.${index}.key`)}
                        </Field>
                        <Field
                            className="w-72"
                            data-invalid={
                                errors[`rows.${index}.label`] ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor={`crow-${index}-label`}>
                                {t('definitionEditor.rowLabel', {
                                    n: index + 1,
                                })}
                            </FieldLabel>
                            <Input
                                id={`crow-${index}-label`}
                                {...register(`rows.${index}.label`)}
                            />
                            {err(`rows.${index}.label`)}
                        </Field>
                        <div className="flex gap-1 self-end">
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                aria-label={t('definitionEditor.moveUp', {
                                    n: index + 1,
                                })}
                                disabled={index === 0}
                                onClick={() => rows.move(index, index - 1)}
                            >
                                <ArrowUpIcon />
                            </Button>
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                aria-label={t('definitionEditor.moveDown', {
                                    n: index + 1,
                                })}
                                disabled={index === rows.fields.length - 1}
                                onClick={() => rows.move(index, index + 1)}
                            >
                                <ArrowDownIcon />
                            </Button>
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                aria-label={t('definitionEditor.removeRow', {
                                    n: index + 1,
                                })}
                                onClick={() => rows.remove(index)}
                            >
                                <Trash2Icon />
                            </Button>
                        </div>
                    </div>
                ))}
                {err('rows')}
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    onClick={() => rows.append({ key: '', label: '' })}
                >
                    <PlusIcon data-icon="inline-start" />
                    {t('definitionEditor.addRow')}
                </Button>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">
                    {t('classificationEditor.rules')}
                </h2>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('classificationEditor.rulesHint')}
                </p>
                {rules.fields.map((rule, index) => {
                    const n = index + 1;

                    return (
                        <fieldset
                            key={rule.id}
                            aria-label={t('classificationEditor.ruleN', { n })}
                            className="flex flex-col gap-3 rounded-lg border p-4"
                        >
                            <div className="flex items-center justify-between">
                                <legend className="text-sm font-medium">
                                    {t('classificationEditor.ruleN', { n })}
                                </legend>
                                <div className="flex gap-1">
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={t(
                                            'classificationEditor.moveRuleUp',
                                            { n },
                                        )}
                                        disabled={index === 0}
                                        onClick={() =>
                                            rules.move(index, index - 1)
                                        }
                                    >
                                        <ArrowUpIcon />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={t(
                                            'classificationEditor.moveRuleDown',
                                            { n },
                                        )}
                                        disabled={
                                            index === rules.fields.length - 1
                                        }
                                        onClick={() =>
                                            rules.move(index, index + 1)
                                        }
                                    >
                                        <ArrowDownIcon />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={t(
                                            'classificationEditor.removeRule',
                                            { n },
                                        )}
                                        onClick={() => rules.remove(index)}
                                    >
                                        <Trash2Icon />
                                    </Button>
                                </div>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    data-invalid={
                                        errors[`rules.${index}.row`]
                                            ? true
                                            : undefined
                                    }
                                >
                                    <FieldLabel htmlFor={`rule-${index}-row`}>
                                        {t('classificationEditor.ruleRow', {
                                            n,
                                        })}
                                    </FieldLabel>
                                    <Controller
                                        control={control}
                                        name={`rules.${index}.row`}
                                        render={({ field }) => (
                                            <OptionSelect
                                                id={`rule-${index}-row`}
                                                value={field.value}
                                                onValueChange={field.onChange}
                                                placeholder={t(
                                                    'classificationEditor.pickRow',
                                                )}
                                                items={rowKeys.map((key) => ({
                                                    value: key,
                                                    label: key,
                                                }))}
                                            />
                                        )}
                                    />
                                    {err(`rules.${index}.row`)}
                                </Field>
                                <Field
                                    data-invalid={
                                        errors[
                                            `rules.${index}.transaction_type_keys`
                                        ]
                                            ? true
                                            : undefined
                                    }
                                >
                                    <FieldLabel htmlFor={`rule-${index}-keys`}>
                                        {t('classificationEditor.ruleKeys', {
                                            n,
                                        })}
                                    </FieldLabel>
                                    <Input
                                        id={`rule-${index}-keys`}
                                        placeholder="sales, payroll"
                                        {...register(`rules.${index}.keys`)}
                                    />
                                    <FieldDescription>
                                        {t('classificationEditor.ruleKeysHint')}
                                    </FieldDescription>
                                    {err(
                                        `rules.${index}.transaction_type_keys`,
                                    )}
                                </Field>
                            </div>

                            <div className="text-sm font-medium">
                                {t('classificationEditor.ruleAccounts')}
                            </div>
                            <SelectorList
                                control={
                                    control as unknown as Control<FieldValues>
                                }
                                name={`rules.${index}.accounts`}
                                errors={errors}
                                accountItems={accountItems}
                                labels={{
                                    type: (s) =>
                                        t('classificationEditor.selectorType', {
                                            n,
                                            s,
                                        }),
                                    class: (s) =>
                                        t(
                                            'classificationEditor.selectorClass',
                                            { n, s },
                                        ),
                                    account: (s) =>
                                        t(
                                            'classificationEditor.selectorAccount',
                                            { n, s },
                                        ),
                                    remove: (s) =>
                                        t(
                                            'classificationEditor.removeSelector',
                                            { n, s },
                                        ),
                                    add: t('classificationEditor.addSelector', {
                                        n,
                                    }),
                                }}
                            />
                        </fieldset>
                    );
                })}
                {err('rules')}
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    onClick={() => rules.append(blankRule())}
                >
                    <PlusIcon data-icon="inline-start" />
                    {t('classificationEditor.addRule')}
                </Button>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">
                    {t('classificationEditor.components')}
                </h2>
                <Controller
                    control={control}
                    name="useComponents"
                    render={({ field }) => (
                        <Field orientation="horizontal" className="w-auto">
                            <Checkbox
                                id="cls-use-components"
                                checked={field.value}
                                onCheckedChange={(checked) => {
                                    field.onChange(checked === true);

                                    if (
                                        checked === true &&
                                        components.fields.length === 0
                                    ) {
                                        components.append(blankComponent());
                                    }
                                }}
                            />
                            <FieldLabel
                                htmlFor="cls-use-components"
                                className="font-normal"
                            >
                                {t('classificationEditor.useComponents')}
                            </FieldLabel>
                        </Field>
                    )}
                />
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('classificationEditor.componentsHint')}
                </p>
                {err('components')}
                {useComponents &&
                    components.fields.map((component, index) => {
                        const n = index + 1;

                        return (
                            <fieldset
                                key={component.id}
                                aria-label={t(
                                    'classificationEditor.componentN',
                                    { n },
                                )}
                                className="flex flex-col gap-3 rounded-lg border p-4"
                            >
                                <div className="flex items-center justify-between">
                                    <legend className="text-sm font-medium">
                                        {t('classificationEditor.componentN', {
                                            n,
                                        })}
                                    </legend>
                                    <Button
                                        type="button"
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={t(
                                            'classificationEditor.removeComponent',
                                            { n },
                                        )}
                                        onClick={() => components.remove(index)}
                                    >
                                        <Trash2Icon />
                                    </Button>
                                </div>
                                <div className="flex flex-wrap gap-3">
                                    <ComponentField
                                        id={`comp-${index}-key`}
                                        label={t(
                                            'classificationEditor.componentKey',
                                            { n },
                                        )}
                                        error={err(`components.${index}.key`)}
                                        invalid={Boolean(
                                            errors[`components.${index}.key`],
                                        )}
                                        register={register}
                                        name={`components.${index}.key`}
                                    />
                                    <ComponentField
                                        id={`comp-${index}-label`}
                                        label={t(
                                            'classificationEditor.componentLabel',
                                            { n },
                                        )}
                                        error={err(`components.${index}.label`)}
                                        invalid={Boolean(
                                            errors[`components.${index}.label`],
                                        )}
                                        register={register}
                                        name={`components.${index}.label`}
                                    />
                                </div>
                                <SelectorList
                                    control={
                                        control as unknown as Control<FieldValues>
                                    }
                                    name={`components.${index}.accounts`}
                                    errors={errors}
                                    accountItems={accountItems}
                                    labels={{
                                        type: (s) =>
                                            t(
                                                'classificationEditor.componentSelectorType',
                                                { n, s },
                                            ),
                                        class: (s) =>
                                            t(
                                                'classificationEditor.componentSelectorClass',
                                                { n, s },
                                            ),
                                        account: (s) =>
                                            t(
                                                'classificationEditor.componentSelectorAccount',
                                                { n, s },
                                            ),
                                        remove: (s) =>
                                            t(
                                                'classificationEditor.removeComponentSelector',
                                                { n, s },
                                            ),
                                        add: t(
                                            'classificationEditor.addComponentSelector',
                                            { n },
                                        ),
                                    }}
                                />
                            </fieldset>
                        );
                    })}
                {useComponents && (
                    <Button
                        type="button"
                        variant="outline"
                        className="self-start"
                        onClick={() => components.append(blankComponent())}
                    >
                        <PlusIcon data-icon="inline-start" />
                        {t('classificationEditor.addComponent')}
                    </Button>
                )}
            </section>

            <SubmitButton
                className="w-auto self-start"
                pending={save.isPending}
                pendingLabel={t('common.saving')}
            >
                {editing
                    ? t('definitionEditor.saveChanges')
                    : t('classificationEditor.create')}
            </SubmitButton>
        </form>
    );
}

function ComponentField({
    id,
    label,
    error,
    invalid,
    register,
    name,
}: {
    id: string;
    label: string;
    error: React.ReactNode;
    invalid: boolean;
    register: Register;
    name: `components.${number}.key` | `components.${number}.label`;
}) {
    return (
        <Field className="w-60" data-invalid={invalid ? true : undefined}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Input id={id} {...register(name)} />
            {error}
        </Field>
    );
}
