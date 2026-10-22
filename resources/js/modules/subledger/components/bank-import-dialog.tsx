import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { UploadIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { MoneyInput } from '@/components/money-input';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
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
import {
    problemsOf,
    readText,
    SHOWN_PROBLEMS,
    type Problem,
} from '@/lib/csv-import';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    bankInvalidationKeys,
    bankMappingQuery,
    importBankStatement,
    type BankStatement,
} from '@/modules/subledger/bank-statements';
import type { CashAccount } from '@/modules/subledger/cash';

function ImportForm({
    companyId,
    cashAccounts,
    initialAccountId,
    canMap,
    onMap,
    onDone,
}: {
    companyId: string;
    cashAccounts: CashAccount[];
    initialAccountId: string;
    canMap: boolean;
    onMap: (account: CashAccount) => void;
    onDone: (statement: BankStatement | null) => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [accountId, setAccountId] = useState(initialAccountId);
    const [file, setFile] = useState<File | null>(null);
    const [closingBalance, setClosingBalance] = useState('');
    const [closingDate, setClosingDate] = useState('');
    const [formError, setFormError] = useState<string | null>(null);
    const [problems, setProblems] = useState<Problem[]>([]);

    const account = cashAccounts.find((row) => row.id === accountId);
    const mapping = useQuery({
        ...bankMappingQuery(companyId, accountId),
        enabled: accountId !== '',
    });
    const items = cashAccounts.map((row) => ({
        value: row.id,
        label: `${row.name} (${row.currency})`,
    }));

    const upload = useMutation({
        mutationFn: async (chosen: File) =>
            importBankStatement(companyId, accountId, {
                csv: await readText(chosen),
                file_name: chosen.name,
                closing_balance: closingBalance,
                closing_date: closingDate || null,
            }),
        onSuccess: async (statement) => {
            await Promise.all(
                bankInvalidationKeys(companyId).map((queryKey) =>
                    queryClient.invalidateQueries({ queryKey }),
                ),
            );
            toast.success(
                t('bankStatements.import.done', {
                    count: statement.line_count,
                }),
            );
            onDone(statement);
        },
        onError: (error) => {
            if (isApiError(error) && error.status === 422) {
                const found = problemsOf(error.errors);

                if (found.length > 0) {
                    setProblems(found);
                    setFormError(t('bankStatements.import.rejected'));

                    return;
                }
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    const unmapped = mapping.data === null;

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                setProblems([]);

                if (account === undefined) {
                    setFormError(t('bankStatements.import.chooseAccount'));
                } else if (unmapped) {
                    setFormError(t('bankStatements.import.needsMapping'));
                } else if (file === null) {
                    setFormError(t('bankStatements.import.chooseFile'));
                } else if (closingBalance.trim() === '') {
                    setFormError(t('bankStatements.import.balanceRequired'));
                } else {
                    upload.mutate(file);
                }
            }}
        >
            <FieldGroup>
                {formError && (
                    <FormAlert>
                        {formError}
                        {problems.length > 0 && (
                            <ul
                                className="mt-2 flex max-h-48 flex-col gap-0.5 overflow-y-auto text-sm"
                                aria-label={t('bankStatements.import.problems')}
                            >
                                {problems.slice(0, SHOWN_PROBLEMS).map((p) => (
                                    <li
                                        key={`${p.line}-${p.field}-${p.message}`}
                                    >
                                        {p.line &&
                                            t('bankStatements.import.line', {
                                                line: p.line,
                                            })}{' '}
                                        {p.field && `${p.field}: `}
                                        {p.message}
                                    </li>
                                ))}
                                {problems.length > SHOWN_PROBLEMS && (
                                    <li>
                                        {t('bankStatements.import.more', {
                                            count:
                                                problems.length -
                                                SHOWN_PROBLEMS,
                                        })}
                                    </li>
                                )}
                            </ul>
                        )}
                    </FormAlert>
                )}

                <Field>
                    <FieldLabel htmlFor="bank-import-account">
                        {t('bankStatements.account')}
                    </FieldLabel>
                    <Select
                        value={accountId}
                        items={items}
                        onValueChange={(value) => {
                            setAccountId(value ?? '');
                            setProblems([]);
                            setFormError(null);
                        }}
                    >
                        <SelectTrigger id="bank-import-account">
                            <SelectValue
                                placeholder={t(
                                    'bankStatements.import.chooseAccount',
                                )}
                            />
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

                {unmapped && account !== undefined && (
                    <FormAlert>
                        {t('bankStatements.import.needsMapping')}
                        {canMap && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-2"
                                onClick={() => onMap(account)}
                            >
                                {t('bankStatements.import.setMapping')}
                            </Button>
                        )}
                    </FormAlert>
                )}

                <Field>
                    <FieldLabel htmlFor="bank-import-file">
                        {t('bankStatements.import.file')}
                    </FieldLabel>
                    <Input
                        id="bank-import-file"
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        onChange={(event) =>
                            setFile(event.target.files?.[0] ?? null)
                        }
                    />
                    <FieldDescription>
                        {t('bankStatements.import.format')}
                    </FieldDescription>
                </Field>

                <div className="flex flex-wrap gap-4">
                    <Field className="w-56">
                        <FieldLabel htmlFor="bank-import-balance">
                            {t('bankStatements.import.closingBalance')}
                        </FieldLabel>
                        <MoneyInput
                            id="bank-import-balance"
                            value={closingBalance}
                            onChange={setClosingBalance}
                        />
                    </Field>
                    <Field className="w-auto">
                        <FieldLabel id="bank-import-date-label">
                            {t('bankStatements.import.closingDate')}
                        </FieldLabel>
                        <DatePicker
                            labelledBy="bank-import-date-label"
                            value={closingDate}
                            clearable
                            placeholder={t('bankStatements.import.lastLine')}
                            onChange={setClosingDate}
                        />
                    </Field>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onDone(null)}
                    >
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={upload.isPending}
                        pendingLabel={t('bankStatements.import.importing')}
                    >
                        <UploadIcon data-icon="inline-start" />
                        {t('bankStatements.import.submit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/**
 * Imports a bank statement into a cash account with the mapping saved for it (SL-FR-13). The file is read here
 * and sent as text; the API refuses the whole file and names every bad line, and the same file twice.
 */
export function BankImportDialog({
    companyId,
    open,
    cashAccounts,
    initialAccountId,
    canMap,
    onMap,
    onOpenChange,
    onImported,
}: {
    companyId: string;
    open: boolean;
    cashAccounts: CashAccount[];
    initialAccountId: string;
    canMap: boolean;
    onMap: (account: CashAccount) => void;
    onOpenChange: (open: boolean) => void;
    onImported: (statement: BankStatement) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('bankStatements.import.title')}
            description={t('bankStatements.import.description')}
            size="lg"
        >
            <ImportForm
                companyId={companyId}
                cashAccounts={cashAccounts}
                initialAccountId={initialAccountId}
                canMap={canMap}
                onMap={onMap}
                onDone={(statement) => {
                    onOpenChange(false);

                    if (statement !== null) {
                        onImported(statement);
                    }
                }}
            />
        </FormDialog>
    );
}
