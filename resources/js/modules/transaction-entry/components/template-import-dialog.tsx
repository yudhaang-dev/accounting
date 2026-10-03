import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
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
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { postingRuleText } from '@/modules/transaction-entry/components/posting-rule-text';
import {
    importTemplate,
    templateQuery,
    templatesQuery,
    type Template,
    type TemplateType,
} from '@/modules/transaction-entry/transaction-types';

function TypeReview({
    type,
    taken,
    checked,
    onChange,
}: {
    type: TemplateType;
    /** The company already has a type with this key; the import would fail as a whole. */
    taken: boolean;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const { t } = useTranslation();
    const id = `import-type-${type.key}`;

    return (
        <li className="flex flex-col gap-2 rounded-lg border p-3">
            <Field orientation="horizontal">
                <Checkbox
                    id={id}
                    checked={checked && !taken}
                    disabled={taken}
                    onCheckedChange={(next) => onChange(next === true)}
                />
                <div className="flex flex-1 flex-wrap items-center gap-2">
                    <FieldLabel htmlFor={id}>{type.label}</FieldLabel>
                    <span className="font-mono text-xs text-muted-foreground">
                        {type.key}
                    </span>
                    <Badge variant="outline">
                        {t(`transactionTypes.modes.${type.mode}`)}
                    </Badge>
                    {taken && (
                        <Badge variant="secondary">
                            {t('transactionTypes.import.taken')}
                        </Badge>
                    )}
                </div>
            </Field>

            {type.mode === 'structured' ? (
                <div className="flex flex-col gap-2 ps-7 text-sm">
                    <div>
                        <p className="font-medium">
                            {t('transactionTypes.import.fields')}
                        </p>
                        <ul className="mt-1 flex flex-col gap-0.5 text-muted-foreground">
                            {type.fields.map((field) => (
                                <li key={field.name}>
                                    {field.label}{' '}
                                    <span className="text-xs">
                                        (
                                        {t(
                                            `transactionTypes.fieldTypes.${field.type}`,
                                        )}
                                        {field.required === false
                                            ? `, ${t('transactionTypes.import.optional')}`
                                            : ''}
                                        )
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div>
                        <p className="font-medium">
                            {t('transactionTypes.import.rules')}
                        </p>
                        <ol className="mt-1 flex list-decimal flex-col gap-0.5 ps-5 text-muted-foreground">
                            {type.posting_rules.map((rule, index) => (
                                <li key={index}>
                                    {postingRuleText(t, rule, type.fields)}
                                </li>
                            ))}
                        </ol>
                    </div>
                </div>
            ) : (
                <p className="ps-7 text-sm text-muted-foreground">
                    {t('transactionTypes.import.freeLines')}
                </p>
            )}
        </li>
    );
}

function ImportForm({
    companyId,
    template,
    existingKeys,
    onDone,
}: {
    companyId: string;
    template: Template;
    existingKeys: Set<string>;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [picked, setPicked] = useState<Set<string>>(
        () =>
            new Set(
                template.types
                    .filter((type) => !existingKeys.has(type.key))
                    .map((type) => type.key),
            ),
    );
    const [confirmed, setConfirmed] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const chosen = template.types.filter(
        (type) => picked.has(type.key) && !existingKeys.has(type.key),
    );

    const run = useMutation({
        mutationFn: () =>
            importTemplate(
                companyId,
                template.key,
                chosen.map((type) => type.key),
            ),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'transaction-types'],
            });
            toast.success(t('transactionTypes.import.done'));
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

                <FieldSet>
                    <FieldLegend variant="label">
                        {t('transactionTypes.import.review')}
                    </FieldLegend>
                    <ul className="flex max-h-80 flex-col gap-2 overflow-y-auto">
                        {template.types.map((type) => (
                            <TypeReview
                                key={type.key}
                                type={type}
                                taken={existingKeys.has(type.key)}
                                checked={picked.has(type.key)}
                                onChange={(on) =>
                                    setPicked((current) => {
                                        const next = new Set(current);

                                        if (on) {
                                            next.add(type.key);
                                        } else {
                                            next.delete(type.key);
                                        }

                                        return next;
                                    })
                                }
                            />
                        ))}
                    </ul>
                </FieldSet>

                <Field orientation="horizontal">
                    <Checkbox
                        id="import-confirmed"
                        checked={confirmed}
                        onCheckedChange={(next) => setConfirmed(next === true)}
                    />
                    <FieldLabel
                        htmlFor="import-confirmed"
                        className="font-normal"
                    >
                        {t('transactionTypes.import.confirm')}
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
                        pendingLabel={t('transactionTypes.import.working')}
                    >
                        {t('transactionTypes.import.action', {
                            count: chosen.length,
                        })}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

function TemplateReview({
    companyId,
    existingKeys,
    onDone,
}: {
    companyId: string;
    existingKeys: Set<string>;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const templates = useQuery(templatesQuery(companyId));
    const [chosen, setChosen] = useState<string | null>(null);
    const key = chosen ?? templates.data?.[0]?.key ?? '';
    const detail = useQuery({
        ...templateQuery(companyId, key),
        enabled: key !== '',
    });

    if (templates.isError) {
        return <FormAlert>{genericErrorMessage(templates.error, t)}</FormAlert>;
    }

    if (templates.data === undefined) {
        return <Skeleton className="h-40 w-full" />;
    }

    if (templates.data.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('transactionTypes.import.noTemplates')}
            </p>
        );
    }

    const items = templates.data.map((template) => ({
        value: template.key,
        label: `${template.name} · ${t('transactionTypes.import.typeCount', { count: template.type_count })}`,
    }));

    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
                {t('transactionTypes.import.intro')}
            </p>
            {templates.data.length > 1 && (
                <Field>
                    <FieldLabel htmlFor="import-template">
                        {t('transactionTypes.import.template')}
                    </FieldLabel>
                    <Select
                        value={key}
                        items={items}
                        onValueChange={(value) => setChosen(value)}
                    >
                        <SelectTrigger id="import-template" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {items.map((item) => (
                                    <SelectItem
                                        key={item.value}
                                        value={item.value}
                                    >
                                        {item.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </Field>
            )}
            {detail.isError ? (
                <FormAlert>{genericErrorMessage(detail.error, t)}</FormAlert>
            ) : detail.data === undefined ? (
                <Skeleton className="h-40 w-full" />
            ) : (
                <ImportForm
                    key={detail.data.key}
                    companyId={companyId}
                    template={detail.data}
                    existingKeys={existingKeys}
                    onDone={onDone}
                />
            )}
        </div>
    );
}

/**
 * Brings in the transaction types a template suggests (TE-FR-01). The user reads each type (mode,
 * fields, posting rules), picks the ones they want and confirms before anything is created (X-AC-03).
 */
export function TemplateImportDialog({
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
            size="lg"
            title={t('transactionTypes.import.title')}
            description={t('transactionTypes.import.intro')}
        >
            <TemplateReview
                companyId={companyId}
                existingKeys={new Set(existingKeys)}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
