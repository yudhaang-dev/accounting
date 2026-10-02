import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { genericErrorMessage } from '@/lib/errors';
import { allAccountsQuery } from '@/modules/general-ledger/accounts';
import {
    AccountMappingTable,
    ConversionSteps,
    CsvSourceField,
    failureMessages,
    ProblemList,
    suggestMapping,
} from '@/modules/general-ledger/components/conversion-parts';
import {
    codesOf,
    GRANULARITIES,
    importHistory,
    isBalanced,
    parseBalances,
    parseJournals,
    type Granularity,
    type OpeningBatch,
    type Problem,
} from '@/modules/general-ledger/conversion';

/** The day before `date`, as `YYYY-MM-DD`. */
function dayBefore(date: string): string {
    const day = new Date(`${date}T00:00:00Z`);

    day.setUTCDate(day.getUTCDate() - 1);

    return day.toISOString().slice(0, 10);
}

/**
 * Imports the history between the start of the migrated data and the cutover (GL-FR-11): the journals
 * of the source, as one per period or one per transaction, and the source trial balance at the
 * cutover, which the ledger must end up matching. Source accounts already translated for the opening
 * balances keep their translation; new ones are chosen here.
 */
export function HistoryImportWizard({
    companyId,
    batch,
}: {
    companyId: string;
    batch: OpeningBatch;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [step, setStep] = useState(1);
    const [sourceKey, setSourceKey] = useState('');
    const [keyError, setKeyError] = useState<string | null>(null);
    const [granularity, setGranularity] =
        useState<Granularity>('period_summary');
    const [journalText, setJournalText] = useState('');
    const [balanceText, setBalanceText] = useState('');
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [failure, setFailure] = useState<string[]>([]);

    const accounts = useQuery(allAccountsQuery(companyId));
    const first = batch.ledger_start_date;
    const last = dayBefore(batch.cutover_date);

    const journals = useMemo(() => parseJournals(journalText), [journalText]);
    const balances = useMemo(() => parseBalances(balanceText), [balanceText]);

    // Dates must lie inside the history, and a period summary has one journal per date and reference.
    const outside: Problem[] = journals.journals.flatMap((journal) =>
        journal.accounting_date < first || journal.accounting_date > last
            ? [
                  {
                      line: 0,
                      kind: 'date' as const,
                      detail: journal.accounting_date,
                  },
              ]
            : [],
    );
    const unbalanced = journals.journals.filter(
        (journal) => !isBalanced(journal.lines),
    );
    const codes = [
        ...new Set([
            ...journals.journals.flatMap((journal) => codesOf(journal.lines)),
            ...codesOf(balances.lines),
        ]),
    ];
    const unmapped = codes.filter((code) => !mapping[code]);

    const submit = useMutation({
        mutationFn: () => {
            const byId = new Map(
                (accounts.data ?? []).map((account) => [
                    account.id,
                    account.code,
                ]),
            );

            return importHistory(companyId, {
                source_key: sourceKey.trim(),
                granularity,
                account_mapping: codes.map((code) => ({
                    source_code: code,
                    account_code: byId.get(mapping[code] ?? '') ?? '',
                })),
                journals: journals.journals.map((journal) => ({
                    accounting_date: journal.accounting_date,
                    description: journal.description,
                    lines: journal.lines.map((line) => ({
                        source_account_code: line.source_account_code,
                        debit: line.debit,
                        credit: line.credit,
                        description: line.description,
                    })),
                })),
                cutover_balances: balances.lines.map((line) => ({
                    source_account_code: line.source_account_code,
                    debit: line.debit,
                    credit: line.credit,
                })),
            });
        },
        onSuccess: async (result) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            });
            toast.success(
                t('conversion.history.done', { count: result.journal_count }),
            );
        },
        onError: (error) =>
            setFailure(failureMessages(error, genericErrorMessage(error, t))),
    });

    const steps = [
        t('conversion.history.steps.details'),
        t('conversion.history.steps.journals'),
        t('conversion.history.steps.balances'),
        t('conversion.history.steps.mapping'),
        t('conversion.history.steps.review'),
    ];
    const options = GRANULARITIES.map((value) => ({
        value,
        label: t(`conversion.history.granularity.${value}`),
    }));

    return (
        <div className="flex max-w-3xl flex-col gap-6">
            <ConversionSteps steps={steps} current={step} />

            {step === 1 && (
                <FieldGroup>
                    <p className="text-sm text-muted-foreground">
                        {t('conversion.history.window', {
                            from: first,
                            to: last,
                        })}
                    </p>
                    <Field data-invalid={keyError ? true : undefined}>
                        <FieldLabel htmlFor="history-source-key">
                            {t('conversion.history.sourceKey')}
                        </FieldLabel>
                        <Input
                            id="history-source-key"
                            value={sourceKey}
                            aria-invalid={keyError ? true : undefined}
                            onChange={(event) =>
                                setSourceKey(event.target.value)
                            }
                        />
                        <FieldDescription>
                            {t('conversion.history.sourceKeyHint')}
                        </FieldDescription>
                        {keyError && <FieldError>{keyError}</FieldError>}
                    </Field>
                    <Field>
                        <FieldLabel id="history-granularity-label">
                            {t('conversion.history.granularityLabel')}
                        </FieldLabel>
                        <Select
                            value={granularity}
                            items={options}
                            onValueChange={(value) =>
                                setGranularity(value as Granularity)
                            }
                        >
                            <SelectTrigger
                                className="w-full"
                                aria-labelledby="history-granularity-label"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    {options.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </Field>
                    <div>
                        <Button
                            type="button"
                            onClick={() => {
                                if (sourceKey.trim() === '') {
                                    setKeyError(t('common.required'));

                                    return;
                                }

                                setKeyError(null);
                                setStep(2);
                            }}
                        >
                            {t('conversion.next')}
                        </Button>
                    </div>
                </FieldGroup>
            )}

            {step === 2 && (
                <FieldGroup>
                    <CsvSourceField
                        label={t('conversion.history.journalsFile')}
                        hint={t(
                            `conversion.history.journalsHint.${granularity}`,
                        )}
                        example={
                            'date,journal,source_code,debit,credit\n2026-04-30,APR,1-1000,500000.00,0\n2026-04-30,APR,4-1000,0,500000.00'
                        }
                        value={journalText}
                        onChange={setJournalText}
                    />
                    <ProblemList
                        problems={[...journals.problems, ...outside]}
                    />
                    {unbalanced.length > 0 && (
                        <p role="alert" className="text-sm text-destructive">
                            {t('conversion.history.unbalancedJournals', {
                                count: unbalanced.length,
                            })}
                        </p>
                    )}
                    {journalText.trim() !== '' &&
                        journals.problems.length +
                            outside.length +
                            unbalanced.length ===
                            0 && (
                            <p className="text-sm text-muted-foreground">
                                {t('conversion.history.read', {
                                    count: journals.journals.length,
                                })}
                            </p>
                        )}
                    <div className="flex gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStep(1)}
                        >
                            {t('conversion.back')}
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                journals.journals.length === 0 ||
                                journals.problems.length +
                                    outside.length +
                                    unbalanced.length >
                                    0
                            }
                            onClick={() => setStep(3)}
                        >
                            {t('conversion.next')}
                        </Button>
                    </div>
                </FieldGroup>
            )}

            {step === 3 && (
                <FieldGroup>
                    <CsvSourceField
                        label={t('conversion.history.balancesFile')}
                        hint={t('conversion.history.balancesHint', {
                            date: batch.cutover_date,
                        })}
                        example={
                            'source_code,debit,credit\n1-1000,6800000.00,0\n3-1000,0,10000000.00'
                        }
                        value={balanceText}
                        onChange={setBalanceText}
                    />
                    <ProblemList problems={balances.problems} />
                    <div className="flex gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStep(2)}
                        >
                            {t('conversion.back')}
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                balances.lines.length === 0 ||
                                balances.problems.length > 0 ||
                                accounts.data === undefined
                            }
                            onClick={() => {
                                setMapping((current) =>
                                    suggestMapping(
                                        codes,
                                        accounts.data ?? [],
                                        current,
                                    ),
                                );
                                setStep(4);
                            }}
                        >
                            {t('conversion.next')}
                        </Button>
                    </div>
                </FieldGroup>
            )}

            {step === 4 && (
                <FieldGroup>
                    <p className="text-sm text-muted-foreground">
                        {t('conversion.history.mappingHint')}
                    </p>
                    {accounts.data === undefined ? (
                        <Skeleton className="h-40 w-full" />
                    ) : (
                        <AccountMappingTable
                            codes={codes}
                            accounts={accounts.data}
                            mapping={mapping}
                            onChange={setMapping}
                        />
                    )}
                    {unmapped.length > 0 && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {t('conversion.unmapped', {
                                count: unmapped.length,
                            })}
                        </p>
                    )}
                    <div className="flex gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStep(3)}
                        >
                            {t('conversion.back')}
                        </Button>
                        <Button
                            type="button"
                            disabled={unmapped.length > 0}
                            onClick={() => setStep(5)}
                        >
                            {t('conversion.next')}
                        </Button>
                    </div>
                </FieldGroup>
            )}

            {step === 5 && (
                <FieldGroup>
                    {failure.length > 0 && (
                        <FormAlert>
                            <ul className="list-disc ps-4">
                                {failure.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </FormAlert>
                    )}
                    <dl className="grid max-w-md grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm">
                        <dt className="text-muted-foreground">
                            {t('conversion.history.sourceKey')}
                        </dt>
                        <dd>{sourceKey}</dd>
                        <dt className="text-muted-foreground">
                            {t('conversion.history.granularityLabel')}
                        </dt>
                        <dd>
                            {t(`conversion.history.granularity.${granularity}`)}
                        </dd>
                        <dt className="text-muted-foreground">
                            {t('conversion.history.journals')}
                        </dt>
                        <dd>{journals.journals.length}</dd>
                        <dt className="text-muted-foreground">
                            {t('conversion.history.balanceLines')}
                        </dt>
                        <dd>{balances.lines.length}</dd>
                    </dl>
                    {!isBalanced(balances.lines) && (
                        <FormAlert>
                            {t('conversion.history.balancesUnbalanced')}
                        </FormAlert>
                    )}
                    <p className="text-sm text-muted-foreground">
                        {t('conversion.history.reviewHint')}
                    </p>
                    <div className="flex gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStep(4)}
                        >
                            {t('conversion.back')}
                        </Button>
                        <SubmitButton
                            className="w-auto"
                            disabled={!isBalanced(balances.lines)}
                            pending={submit.isPending}
                            pendingLabel={t('conversion.importing')}
                            onClick={() => {
                                setFailure([]);
                                submit.mutate();
                            }}
                        >
                            {t('conversion.history.import')}
                        </SubmitButton>
                    </div>
                </FieldGroup>
            )}
        </div>
    );
}
