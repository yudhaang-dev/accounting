import {
    useMutation,
    useQuery,
    useQueryClient,
    useSuspenseQuery,
} from '@tanstack/react-query';
import { Suspense, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    accountTemplateQuery,
    accountTemplatesQuery,
    chartSizeQuery,
    importTemplate,
} from '@/modules/general-ledger/chart';

/** First message of a 422, which names the account or template at fault. */
const firstMessage = (error: unknown): string | undefined =>
    isApiError(error)
        ? (Object.values(error.errors)[0]?.[0] ?? error.message)
        : undefined;

function TemplateReview({
    companyId,
    templateKey,
}: {
    companyId: string;
    templateKey: string;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const { data: template } = useSuspenseQuery(
        accountTemplateQuery(companyId, templateKey),
    );
    const [excluded, setExcluded] = useState<ReadonlySet<string>>(new Set());
    const [confirmed, setConfirmed] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    // An account may be left out only when it is optional and nothing hangs below it.
    const parents = useMemo(
        () => new Set(template.accounts.map((a) => a.parent)),
        [template],
    );
    const depth = useMemo(() => {
        const parentOf = new Map(
            template.accounts.map((a) => [a.code, a.parent]),
        );
        const levels = new Map<string, number>();

        for (const { code } of template.accounts) {
            let level = 0;

            for (
                let parent = parentOf.get(code);
                parent;
                parent = parentOf.get(parent)
            ) {
                level++;
            }

            levels.set(code, level);
        }

        return levels;
    }, [template]);
    const included = template.accounts.length - excluded.size;

    const install = useMutation({
        mutationFn: () =>
            importTemplate(companyId, {
                template: template.key,
                exclude: [...excluded],
            }),
        onSuccess: () =>
            queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            }),
        onError: (error) => {
            setFormError(firstMessage(error) ?? genericErrorMessage(error, t));

            // Someone else installed a chart meanwhile: show what is there now.
            if (isApiError(error) && error.status === 409) {
                void queryClient.invalidateQueries({
                    queryKey: chartSizeQuery(companyId).queryKey,
                });
            }
        },
    });

    const toggle = (code: string, install: boolean) =>
        setExcluded((current) => {
            const next = new Set(current);

            if (install) {
                next.delete(code);
            } else {
                next.add(code);
            }

            return next;
        });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                install.mutate();
            }}
        >
            <FieldGroup>
                <div className="flex flex-col gap-1">
                    <h3 className="text-sm font-medium">
                        {t('setup.chart.review')}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {t('setup.chart.optionalHint')}
                    </p>
                </div>

                <div className="max-h-96 overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader className="sticky top-0 bg-card">
                            <TableRow>
                                <TableHead className="w-12">
                                    {t('setup.chart.include')}
                                </TableHead>
                                <TableHead>{t('setup.chart.code')}</TableHead>
                                <TableHead>
                                    {t('setup.chart.accountName')}
                                </TableHead>
                                <TableHead>{t('setup.chart.class')}</TableHead>
                                <TableHead>{t('setup.chart.normal')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {template.accounts.map((account) => {
                                const canLeaveOut =
                                    account.optional &&
                                    !parents.has(account.code);
                                const isIncluded = !excluded.has(account.code);

                                return (
                                    <TableRow
                                        key={account.code}
                                        className={
                                            isIncluded
                                                ? undefined
                                                : 'opacity-50'
                                        }
                                    >
                                        <TableCell>
                                            <Checkbox
                                                checked={isIncluded}
                                                disabled={!canLeaveOut}
                                                aria-label={t(
                                                    'setup.chart.includeAccount',
                                                    { code: account.code },
                                                )}
                                                onCheckedChange={(value) =>
                                                    toggle(
                                                        account.code,
                                                        value === true,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {account.code}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                style={{
                                                    paddingInlineStart: `${
                                                        (depth.get(
                                                            account.code,
                                                        ) ?? 0) * 16
                                                    }px`,
                                                }}
                                            >
                                                {account.name}
                                            </span>
                                            {account.optional && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    {t('setup.chart.optional')}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {t(
                                                `accountClass.${account.class}`,
                                                {
                                                    defaultValue: account.class,
                                                },
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {account.normal
                                                ? t(
                                                      `normalBalance.${account.normal}`,
                                                      {
                                                          defaultValue:
                                                              account.normal,
                                                      },
                                                  )
                                                : '—'}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                <p className="text-sm text-muted-foreground" aria-live="polite">
                    {t('setup.chart.summary', {
                        included,
                        total: template.accounts.length,
                    })}
                </p>

                {formError && <FormAlert>{formError}</FormAlert>}

                <Field orientation="horizontal">
                    <Checkbox
                        id="chart-confirm"
                        checked={confirmed}
                        onCheckedChange={(value) =>
                            setConfirmed(value === true)
                        }
                    />
                    <FieldLabel htmlFor="chart-confirm" className="font-normal">
                        {t('setup.chart.confirm')}
                    </FieldLabel>
                </Field>

                <SubmitButton
                    className="w-auto self-start"
                    pending={install.isPending}
                    pendingLabel={t('setup.chart.submitting')}
                    disabled={!confirmed}
                >
                    {t('setup.chart.submit')}
                </SubmitButton>
            </FieldGroup>
        </form>
    );
}

/**
 * Chart of accounts step (GL-FR-01, X-AC-03): choose a template, review every account, optionally
 * leave out optional ones, and confirm. Nothing is created before the confirmation.
 */
export function ChartStep({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const size = useQuery(chartSizeQuery(companyId));
    const templates = useQuery(accountTemplatesQuery(companyId));
    const [chosen, setChosen] = useState<string | null>(null);

    if (size.isError || templates.isError) {
        return (
            <FormAlert>
                {genericErrorMessage(size.error ?? templates.error, t)}
            </FormAlert>
        );
    }

    if (size.data === undefined || templates.data === undefined) {
        return <Skeleton className="h-24 w-full" />;
    }

    if (size.data > 0) {
        return (
            <div className="flex flex-col gap-1">
                <p className="font-medium">{t('setup.chart.doneTitle')}</p>
                <p className="text-sm text-muted-foreground">
                    {t('setup.chart.doneDescription', { count: size.data })}
                </p>
            </div>
        );
    }

    const selected = chosen ?? templates.data[0]?.key ?? null;

    return (
        <div className="flex flex-col gap-5">
            <p className="text-sm text-muted-foreground">
                {t('setup.chart.intro')}
            </p>

            <RadioGroup
                aria-label={t('setup.chart.template')}
                value={selected ?? ''}
                onValueChange={(value) => setChosen(String(value))}
            >
                {templates.data.map((template) => (
                    <div key={template.key} className="flex items-center gap-2">
                        <RadioGroupItem
                            id={`template-${template.key}`}
                            value={template.key}
                        />
                        <Label htmlFor={`template-${template.key}`}>
                            {template.name}
                            <span className="font-normal text-muted-foreground">
                                {' · '}
                                {t('setup.chart.templateMeta', {
                                    count: template.account_count,
                                })}
                            </span>
                        </Label>
                    </div>
                ))}
            </RadioGroup>

            {selected && (
                <Suspense fallback={<Skeleton className="h-48 w-full" />}>
                    {/* Keyed so switching templates starts from a clean review. */}
                    <TemplateReview
                        key={selected}
                        companyId={companyId}
                        templateKey={selected}
                    />
                </Suspense>
            )}
        </div>
    );
}
