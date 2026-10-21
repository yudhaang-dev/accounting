import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
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
import { Skeleton } from '@/components/ui/skeleton';
import { formatDecimal } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { allAccountsQuery } from '@/modules/general-ledger/accounts';
import {
    AccountMappingTable,
    ConversionSteps,
    CsvSourceField,
    failureMessages,
    PartyMappingTable,
    ProblemList,
    suggestMapping,
    suggestPartyMapping,
} from '@/modules/general-ledger/components/conversion-parts';
import {
    codesOf,
    controlMismatches,
    importOpeningBalances,
    isBalanced,
    openItemTotals,
    parseBalances,
    parseOpenItems,
    partyRefsOf,
    totalsOf,
} from '@/modules/general-ledger/conversion';
import { partyOptionsQuery } from '@/modules/subledger/documents';
import {
    postingProfilesQuery,
    type ControlRoleKey,
} from '@/modules/subledger/posting-profiles';

type Details = {
    source_key: string;
    source_system: string;
    cutover_date: string;
    /** Empty means the same as the cutover date: no history. */
    ledger_start_date: string;
};

type DetailErrors = Partial<Record<keyof Details, string>>;

/**
 * Imports the opening balances of the company from the trial balance of its previous system
 * (GL-FR-11): the dates, the file, the translation of the source accounts, and a review before the
 * one journal is posted. The import happens once; the same file sent again changes nothing.
 */
export function OpeningBalanceWizard({ companyId }: { companyId: string }) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [step, setStep] = useState(1);
    const [details, setDetails] = useState<Details>({
        source_key: '',
        source_system: '',
        cutover_date: '',
        ledger_start_date: '',
    });
    const [detailErrors, setDetailErrors] = useState<DetailErrors>({});
    const [text, setText] = useState('');
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [openText, setOpenText] = useState('');
    const [partyMapping, setPartyMapping] = useState<Record<string, string>>(
        {},
    );
    const [failure, setFailure] = useState<string[]>([]);

    const accounts = useQuery(allAccountsQuery(companyId));
    const parties = useQuery(partyOptionsQuery(companyId));
    const profiles = useQuery(postingProfilesQuery(companyId));
    const parsedItems = useMemo(() => parseOpenItems(openText), [openText]);
    const hasItems = parsedItems.items.length > 0;
    const refs = partyRefsOf(parsedItems.items);
    const unmappedParties = refs.filter((ref) => !partyMapping[ref]);
    const reviewStep = hasItems ? 5 : 4;
    const itemTotals = openItemTotals(parsedItems.items);
    const defaultProfile =
        profiles.data?.find((profile) => profile.is_default) ?? null;
    const roleAccounts: Partial<Record<ControlRoleKey, string>> = {};

    for (const row of defaultProfile?.accounts ?? []) {
        if (row.account_id !== null) {
            roleAccounts[row.key] = row.account_id;
        }
    }

    const parsed = useMemo(() => parseBalances(text), [text]);
    const codes = codesOf(parsed.lines);
    const unmapped = codes.filter((code) => !mapping[code]);
    const totals = totalsOf(parsed.lines);
    const balanced = isBalanced(parsed.lines);
    const startDate = details.ledger_start_date || details.cutover_date;
    const mismatches =
        hasItems && itemTotals !== null
            ? controlMismatches(parsed.lines, mapping, roleAccounts, itemTotals)
            : [];
    const accountCode = (id: string) =>
        accounts.data?.find((account) => account.id === id)?.code ?? id;

    const validateDetails = (): boolean => {
        const errors: DetailErrors = {};

        if (details.source_key.trim() === '') {
            errors.source_key = t('common.required');
        }

        if (details.cutover_date === '') {
            errors.cutover_date = t('common.required');
        }

        if (
            details.ledger_start_date !== '' &&
            details.cutover_date !== '' &&
            details.ledger_start_date > details.cutover_date
        ) {
            errors.ledger_start_date = t(
                'conversion.opening.startAfterCutover',
            );
        }

        setDetailErrors(errors);

        return Object.keys(errors).length === 0;
    };

    const submit = useMutation({
        mutationFn: () => {
            const byId = new Map(
                (accounts.data ?? []).map((account) => [
                    account.id,
                    account.code,
                ]),
            );

            return importOpeningBalances(companyId, {
                source_key: details.source_key.trim(),
                source_system: details.source_system.trim() || null,
                cutover_date: details.cutover_date,
                ledger_start_date: startDate,
                account_mapping: codes.map((code) => ({
                    source_code: code,
                    account_code: byId.get(mapping[code] ?? '') ?? '',
                })),
                lines: parsed.lines.map((line) => ({
                    source_account_code: line.source_account_code,
                    debit: line.debit,
                    credit: line.credit,
                    description: line.description,
                })),
                ...(hasItems
                    ? {
                          open_items: parsedItems.items.map((item) => ({
                              party_id: partyMapping[item.party_ref] ?? null,
                              control_role: item.control_role,
                              kind: item.kind,
                              document_number: item.document_number,
                              document_date: item.document_date,
                              due_date: item.due_date,
                              currency: item.currency,
                              amount: item.amount,
                              exchange_rate: item.exchange_rate,
                          })),
                      }
                    : {}),
            });
        },
        onSuccess: async (batch) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            });
            toast.success(
                t('conversion.opening.done', { number: batch.journal_number }),
            );
        },
        onError: (error) =>
            setFailure(failureMessages(error, genericErrorMessage(error, t))),
    });

    const steps = [
        t('conversion.opening.steps.details'),
        t('conversion.opening.steps.file'),
        t('conversion.opening.steps.mapping'),
        ...(hasItems ? [t('conversion.opening.steps.parties')] : []),
        t('conversion.opening.steps.review'),
    ];

    const toParties = () => {
        setPartyMapping((current) =>
            suggestPartyMapping(
                refs,
                parties.data ?? [],
                details.source_system.trim(),
                current,
            ),
        );
        setStep(4);
    };

    const toMapping = () => {
        setMapping((current) =>
            suggestMapping(codes, accounts.data ?? [], current),
        );
        setStep(3);
    };

    return (
        <div className="flex max-w-3xl flex-col gap-6">
            <ConversionSteps steps={steps} current={step} />

            {step === 1 && (
                <FieldGroup>
                    <Field
                        data-invalid={
                            detailErrors.source_key ? true : undefined
                        }
                    >
                        <FieldLabel htmlFor="opening-source-key">
                            {t('conversion.opening.sourceKey')}
                        </FieldLabel>
                        <Input
                            id="opening-source-key"
                            autoFocus
                            value={details.source_key}
                            aria-invalid={
                                detailErrors.source_key ? true : undefined
                            }
                            onChange={(event) =>
                                setDetails({
                                    ...details,
                                    source_key: event.target.value,
                                })
                            }
                        />
                        <FieldDescription>
                            {t('conversion.opening.sourceKeyHint')}
                        </FieldDescription>
                        {detailErrors.source_key && (
                            <FieldError>{detailErrors.source_key}</FieldError>
                        )}
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="opening-source-system">
                            {t('conversion.opening.sourceSystem')}
                        </FieldLabel>
                        <Input
                            id="opening-source-system"
                            value={details.source_system}
                            onChange={(event) =>
                                setDetails({
                                    ...details,
                                    source_system: event.target.value,
                                })
                            }
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            data-invalid={
                                detailErrors.cutover_date ? true : undefined
                            }
                        >
                            <FieldLabel id="opening-cutover-label">
                                {t('conversion.opening.cutover')}
                            </FieldLabel>
                            <DatePicker
                                id="opening-cutover"
                                labelledBy="opening-cutover-label"
                                value={details.cutover_date}
                                onChange={(value) =>
                                    setDetails({
                                        ...details,
                                        cutover_date: value,
                                    })
                                }
                                aria-invalid={
                                    detailErrors.cutover_date ? true : undefined
                                }
                            />
                            <FieldDescription>
                                {t('conversion.opening.cutoverHint')}
                            </FieldDescription>
                            {detailErrors.cutover_date && (
                                <FieldError>
                                    {detailErrors.cutover_date}
                                </FieldError>
                            )}
                        </Field>
                        <Field
                            data-invalid={
                                detailErrors.ledger_start_date
                                    ? true
                                    : undefined
                            }
                        >
                            <FieldLabel id="opening-start-label">
                                {t('conversion.opening.start')}
                            </FieldLabel>
                            <DatePicker
                                id="opening-start"
                                labelledBy="opening-start-label"
                                value={details.ledger_start_date}
                                onChange={(value) =>
                                    setDetails({
                                        ...details,
                                        ledger_start_date: value,
                                    })
                                }
                                clearable
                                aria-invalid={
                                    detailErrors.ledger_start_date
                                        ? true
                                        : undefined
                                }
                            />
                            <FieldDescription>
                                {t('conversion.opening.startHint')}
                            </FieldDescription>
                            {detailErrors.ledger_start_date && (
                                <FieldError>
                                    {detailErrors.ledger_start_date}
                                </FieldError>
                            )}
                        </Field>
                    </div>
                    <div>
                        <Button
                            type="button"
                            onClick={() => {
                                if (validateDetails()) {
                                    setStep(2);
                                }
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
                        label={t('conversion.opening.file')}
                        hint={t('conversion.opening.fileHint', {
                            date: startDate,
                        })}
                        example={
                            'source_code,debit,credit\n1-1000,7000000.00,0\n3-1000,0,7000000.00'
                        }
                        value={text}
                        onChange={setText}
                    />
                    <ProblemList problems={parsed.problems} />
                    {text.trim() !== '' && parsed.problems.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('conversion.read', {
                                count: parsed.lines.length,
                            })}
                        </p>
                    )}
                    <CsvSourceField
                        label={t('conversion.opening.itemsFile')}
                        hint={t('conversion.opening.itemsFileHint')}
                        example={
                            'control_role,party,kind,document_number,document_date,due_date,amount\nreceivables,C-001,invoice,INV-0001,2026-03-15,2026-04-14,1500000.00'
                        }
                        value={openText}
                        onChange={setOpenText}
                    />
                    <ProblemList problems={parsedItems.problems} />
                    {hasItems && parsedItems.problems.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('conversion.opening.itemsRead', {
                                count: parsedItems.items.length,
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
                                parsed.lines.length === 0 ||
                                parsed.problems.length > 0 ||
                                parsedItems.problems.length > 0 ||
                                accounts.data === undefined
                            }
                            onClick={toMapping}
                        >
                            {t('conversion.next')}
                        </Button>
                    </div>
                </FieldGroup>
            )}

            {step === 3 && (
                <FieldGroup>
                    <p className="text-sm text-muted-foreground">
                        {t('conversion.mappingHint')}
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
                            onClick={() => setStep(2)}
                        >
                            {t('conversion.back')}
                        </Button>
                        <Button
                            type="button"
                            disabled={unmapped.length > 0}
                            onClick={() =>
                                hasItems ? toParties() : setStep(reviewStep)
                            }
                        >
                            {t('conversion.next')}
                        </Button>
                    </div>
                </FieldGroup>
            )}

            {step === 4 && hasItems && (
                <FieldGroup>
                    <p className="text-sm text-muted-foreground">
                        {t('conversion.opening.partiesHint')}
                    </p>
                    {parties.data === undefined ? (
                        <Skeleton className="h-40 w-full" />
                    ) : (
                        <PartyMappingTable
                            refs={refs}
                            parties={parties.data}
                            mapping={partyMapping}
                            onChange={setPartyMapping}
                        />
                    )}
                    {unmappedParties.length > 0 && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {t('conversion.opening.partiesUnmapped', {
                                count: unmappedParties.length,
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
                            disabled={unmappedParties.length > 0}
                            onClick={() => setStep(reviewStep)}
                        >
                            {t('conversion.next')}
                        </Button>
                    </div>
                </FieldGroup>
            )}

            {step === reviewStep && (
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
                            {t('conversion.opening.sourceKey')}
                        </dt>
                        <dd>{details.source_key}</dd>
                        <dt className="text-muted-foreground">
                            {t('conversion.opening.cutover')}
                        </dt>
                        <dd>{details.cutover_date}</dd>
                        <dt className="text-muted-foreground">
                            {t('conversion.opening.start')}
                        </dt>
                        <dd>{startDate}</dd>
                        <dt className="text-muted-foreground">
                            {t('conversion.lines')}
                        </dt>
                        <dd>{parsed.lines.length}</dd>
                        <dt className="text-muted-foreground">
                            {t('conversion.debit')}
                        </dt>
                        <dd className="tabular-nums">
                            {formatDecimal(totals.debit, i18n.language)}
                        </dd>
                        <dt className="text-muted-foreground">
                            {t('conversion.credit')}
                        </dt>
                        <dd className="tabular-nums">
                            {formatDecimal(totals.credit, i18n.language)}
                        </dd>
                        {hasItems && (
                            <>
                                <dt className="text-muted-foreground">
                                    {t('conversion.opening.items')}
                                </dt>
                                <dd>{parsedItems.items.length}</dd>
                            </>
                        )}
                    </dl>
                    {mismatches.map((mismatch) => (
                        <FormAlert key={mismatch.role}>
                            {t('conversion.opening.controlDiffers', {
                                account: accountCode(mismatch.accountId),
                                balance: formatDecimal(
                                    mismatch.balance,
                                    i18n.language,
                                ),
                                items: formatDecimal(
                                    mismatch.openItems,
                                    i18n.language,
                                ),
                            })}
                        </FormAlert>
                    ))}
                    {hasItems && itemTotals === null && (
                        <p className="text-sm text-muted-foreground">
                            {t('conversion.opening.controlByServer')}
                        </p>
                    )}
                    {!balanced && (
                        <FormAlert>{t('conversion.unbalanced')}</FormAlert>
                    )}
                    <div className="flex gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStep(hasItems ? 4 : 3)}
                        >
                            {t('conversion.back')}
                        </Button>
                        <SubmitButton
                            className="w-auto"
                            disabled={!balanced || mismatches.length > 0}
                            pending={submit.isPending}
                            pendingLabel={t('conversion.importing')}
                            onClick={() => {
                                setFailure([]);
                                submit.mutate();
                            }}
                        >
                            {t('conversion.opening.import')}
                        </SubmitButton>
                    </div>
                </FieldGroup>
            )}
        </div>
    );
}
