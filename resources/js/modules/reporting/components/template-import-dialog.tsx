import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Skeleton } from '@/components/ui/skeleton';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { allAccountsQuery } from '@/modules/general-ledger/accounts';
import {
    importTemplate,
    missingCodes,
    templateQuery,
    templatesQuery,
    type Template,
    type TemplateDefinition,
    type TemplateSelector,
} from '@/modules/reporting/report-templates';
import { OptionSelect } from '@/modules/transaction-entry/components/option-select';

type ChartAccounts = Map<string, string>;

type RowView = {
    key: string;
    label: string;
    type: string;
    accounts?: TemplateSelector[];
};

function DefinitionReview({
    definition,
    taken,
    checked,
    chart,
    onChange,
}: {
    definition: TemplateDefinition;
    /** The company already has a definition with this key; the import would fail as a whole. */
    taken: boolean;
    checked: boolean;
    /** Account name by code, or undefined when the chart cannot be read. */
    chart: ChartAccounts | undefined;
    onChange: (checked: boolean) => void;
}) {
    const { t } = useTranslation();
    const id = `import-def-${definition.key}`;
    const rows = (definition.structure.rows ?? []) as RowView[];
    const columns = (definition.structure.columns ?? []) as {
        key: string;
        label: string;
    }[];
    const selectorText = (selector: TemplateSelector) =>
        selector.class
            ? t(`accountClass.${selector.class as 'asset'}`)
            : `${selector.code}${chart?.get(selector.code ?? '') ? ` ${chart.get(selector.code ?? '')}` : ''}`;
    const missing = chart
        ? missingCodes(definition, new Set(chart.keys()))
        : [];

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
                    <FieldLabel htmlFor={id}>{definition.name}</FieldLabel>
                    <span className="font-mono text-xs text-muted-foreground">
                        {definition.key}
                    </span>
                    <Badge variant="outline">
                        {t(`reports.kinds.${definition.kind as 'custom'}`, {
                            defaultValue: definition.kind,
                        })}
                    </Badge>
                    {taken && (
                        <Badge variant="secondary">
                            {t('reportImport.taken')}
                        </Badge>
                    )}
                    {missing.length > 0 && (
                        <Badge variant="destructive">
                            {t('reportImport.missingBadge', {
                                count: missing.length,
                            })}
                        </Badge>
                    )}
                </div>
            </Field>

            <div className="flex flex-col gap-2 ps-7 text-sm">
                <div>
                    <p className="font-medium">{t('reportImport.rows')}</p>
                    <ul className="mt-1 flex flex-col gap-0.5 text-muted-foreground">
                        {rows.map((row) => (
                            <li key={row.key}>
                                {row.label}
                                {row.type === 'accounts' && (
                                    <span className="text-xs">
                                        {' · '}
                                        {(row.accounts ?? [])
                                            .map(selectorText)
                                            .join(', ')}
                                    </span>
                                )}
                                {row.type !== 'accounts' &&
                                    row.type !== 'heading' && (
                                        <span className="text-xs">
                                            {' · '}
                                            {t(
                                                `definitionEditor.rowTypes.${row.type as 'subtotal'}`,
                                            )}
                                        </span>
                                    )}
                            </li>
                        ))}
                    </ul>
                </div>
                <div>
                    <p className="font-medium">{t('reportImport.columns')}</p>
                    <p className="text-muted-foreground">
                        {columns.map((column) => column.label).join(', ')}
                    </p>
                </div>
            </div>
        </li>
    );
}

function ImportForm({
    companyId,
    template,
    existingKeys,
    chart,
    onDone,
}: {
    companyId: string;
    template: Template;
    existingKeys: Set<string>;
    chart: ChartAccounts | undefined;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [picked, setPicked] = useState<Set<string>>(
        () =>
            new Set(
                template.definitions
                    .filter((definition) => !existingKeys.has(definition.key))
                    .map((definition) => definition.key),
            ),
    );
    const [confirmed, setConfirmed] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const chosen = template.definitions.filter(
        (definition) =>
            picked.has(definition.key) && !existingKeys.has(definition.key),
    );
    // Codes the chart lacks, per picked definition: the server would refuse the import as a whole.
    const problems = chart
        ? chosen.flatMap((definition) =>
              missingCodes(definition, new Set(chart.keys())).map((code) => ({
                  code,
                  definition: definition.name,
              })),
          )
        : [];

    const run = useMutation({
        mutationFn: () =>
            importTemplate(
                companyId,
                template.key,
                chosen.map((definition) => definition.key),
            ),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'report-definitions'],
            });
            toast.success(t('reportImport.done'));
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
                        {t('reportImport.review')}
                    </FieldLegend>
                    <ul className="flex max-h-80 flex-col gap-2 overflow-y-auto">
                        {template.definitions.map((definition) => (
                            <DefinitionReview
                                key={definition.key}
                                definition={definition}
                                taken={existingKeys.has(definition.key)}
                                checked={picked.has(definition.key)}
                                chart={chart}
                                onChange={(on) =>
                                    setPicked((current) => {
                                        const next = new Set(current);

                                        if (on) {
                                            next.add(definition.key);
                                        } else {
                                            next.delete(definition.key);
                                        }

                                        return next;
                                    })
                                }
                            />
                        ))}
                    </ul>
                </FieldSet>

                {problems.length > 0 && (
                    <Alert variant="destructive" role="alert">
                        <AlertTitle>
                            {t('reportImport.missingTitle')}
                        </AlertTitle>
                        <AlertDescription>
                            <p>{t('reportImport.missingDescription')}</p>
                            <ul className="mt-1 list-inside list-disc">
                                {problems.map((problem) => (
                                    <li
                                        key={`${problem.definition}-${problem.code}`}
                                    >
                                        {t('reportImport.missingItem', problem)}
                                    </li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <Field orientation="horizontal">
                    <Checkbox
                        id="report-import-confirmed"
                        checked={confirmed}
                        onCheckedChange={(next) => setConfirmed(next === true)}
                    />
                    <FieldLabel
                        htmlFor="report-import-confirmed"
                        className="font-normal"
                    >
                        {t('reportImport.confirm')}
                    </FieldLabel>
                </Field>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        disabled={
                            !confirmed ||
                            chosen.length === 0 ||
                            problems.length > 0
                        }
                        pending={run.isPending}
                        pendingLabel={t('reportImport.working')}
                    >
                        {t('reportImport.action', { count: chosen.length })}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

function TemplateReview({
    companyId,
    existingKeys,
    canReadAccounts,
    onDone,
}: {
    companyId: string;
    existingKeys: Set<string>;
    canReadAccounts: boolean;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const templates = useQuery(templatesQuery(companyId));
    const accounts = useQuery({
        ...allAccountsQuery(companyId),
        enabled: canReadAccounts,
    });
    const [chosen, setChosen] = useState<string | null>(null);
    const key = chosen ?? templates.data?.[0]?.key ?? '';
    const detail = useQuery({
        ...templateQuery(companyId, key),
        enabled: key !== '',
    });

    if (templates.isError) {
        return <FormAlert>{genericErrorMessage(templates.error, t)}</FormAlert>;
    }

    if (templates.data === undefined || (canReadAccounts && !accounts.data)) {
        return <Skeleton className="h-40 w-full" />;
    }

    if (templates.data.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('reportImport.noTemplates')}
            </p>
        );
    }

    const chart: ChartAccounts | undefined = accounts.data
        ? new Map(accounts.data.map((account) => [account.code, account.name]))
        : undefined;
    const items = templates.data.map((template) => ({
        value: template.key,
        label: `${template.name} · ${t('reportImport.count', { count: template.definition_count })}`,
    }));

    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
                {t('reportImport.intro')}
            </p>
            {!canReadAccounts && (
                <p className="text-sm text-muted-foreground">
                    {t('reportImport.noChartAccess')}
                </p>
            )}
            {templates.data.length > 1 && (
                <Field>
                    <FieldLabel htmlFor="report-import-template">
                        {t('reportImport.template')}
                    </FieldLabel>
                    <OptionSelect
                        id="report-import-template"
                        value={key}
                        items={items}
                        onValueChange={setChosen}
                    />
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
                    chart={chart}
                    onDone={onDone}
                />
            )}
        </div>
    );
}

/**
 * Brings in the report definitions a template suggests (RP-FR-01). The user reads each definition (rows,
 * columns, the accounts it names), sees which template accounts the chart lacks, picks and confirms
 * before anything is created (X-AC-03).
 */
export function ReportTemplateImportDialog({
    companyId,
    existingKeys,
    canReadAccounts,
    open,
    onOpenChange,
}: {
    companyId: string;
    existingKeys: string[];
    canReadAccounts: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title={t('reportImport.title')}
            description={t('reportImport.intro')}
        >
            <TemplateReview
                companyId={companyId}
                existingKeys={new Set(existingKeys)}
                canReadAccounts={canReadAccounts}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
