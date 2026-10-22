import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { compareDecimals, formatMoney } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import {
    bankInvalidationKeys,
    bankSuggestionsQuery,
    matchBankLine,
    type BankStatementLine,
} from '@/modules/subledger/bank-statements';
import { cashTransactionsQuery } from '@/modules/subledger/cash';

const WINDOW_DAYS = 7;

function Candidates({
    companyId,
    statementId,
    line,
    onDone,
}: {
    companyId: string;
    statementId: string;
    line: BankStatementLine;
    onDone: () => void;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);

    const suggestions = useQuery(
        bankSuggestionsQuery(companyId, statementId, WINDOW_DAYS),
    );
    const transactions = useQuery(
        cashTransactionsQuery(companyId, {
            cashAccountId: line.cash_account_id,
            dateFrom: '',
            dateTo: '',
            page: 1,
            perPage: 200,
        }),
    );

    const match = useMutation({
        mutationFn: (transactionId: string) =>
            matchBankLine(companyId, line.id, transactionId),
        onSuccess: async () => {
            await Promise.all(
                bankInvalidationKeys(companyId).map((queryKey) =>
                    queryClient.invalidateQueries({ queryKey }),
                ),
            );
            toast.success(t('bankStatements.match.done'));
            onDone();
        },
        onError: (error) => setFormError(actionErrorMessage(error, t)),
    });

    if (suggestions.isError || transactions.isError) {
        return (
            <FormAlert>
                {genericErrorMessage(
                    suggestions.error ?? transactions.error,
                    t,
                )}
            </FormAlert>
        );
    }

    if (suggestions.data === undefined || transactions.data === undefined) {
        return <Skeleton className="h-32 w-full" />;
    }

    const ranked = new Map(
        (
            suggestions.data.find((row) => row.line_id === line.id)
                ?.candidates ?? []
        ).map((candidate) => [candidate.cash_transaction_id, candidate]),
    );
    const candidates = transactions.data.transactions
        .filter(
            (transaction) =>
                transaction.direction === line.direction &&
                compareDecimals(transaction.amount, line.amount) === 0,
        )
        .sort(
            (a, b) =>
                (ranked.get(b.id)?.score ?? -1) -
                    (ranked.get(a.id)?.score ?? -1) ||
                a.transaction_date.localeCompare(b.transaction_date),
        );

    return (
        <div className="flex flex-col gap-3">
            {formError && <FormAlert>{formError}</FormAlert>}
            <p className="text-sm text-muted-foreground">
                {t('bankStatements.match.line', {
                    date: line.transaction_date,
                    direction: t(
                        `cashTransactions.directions.${line.direction === 'in' ? 'in' : 'out'}`,
                    ),
                    amount: formatMoney(line.amount, i18n.language),
                    text: line.reference ?? line.description ?? '—',
                })}
            </p>
            {candidates.length === 0 ? (
                <p className="rounded-lg border px-4 py-6 text-center text-sm text-muted-foreground">
                    {t('bankStatements.match.none')}
                </p>
            ) : (
                <div className="max-h-72 overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('cashTransactions.date')}
                                </TableHead>
                                <TableHead>
                                    {t('cashTransactions.reference')}
                                </TableHead>
                                <TableHead>
                                    {t('cashTransactions.state')}
                                </TableHead>
                                <TableHead className="w-24">
                                    <span className="sr-only">
                                        {t('cashTransactions.actions')}
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {candidates.map((transaction) => {
                                const suggestion = ranked.get(transaction.id);

                                return (
                                    <TableRow key={transaction.id}>
                                        <TableCell className="tabular-nums">
                                            {transaction.transaction_date}
                                        </TableCell>
                                        <TableCell>
                                            {transaction.reference ?? '—'}
                                            {suggestion && (
                                                <Badge
                                                    variant="secondary"
                                                    className="ms-2"
                                                >
                                                    {t(
                                                        suggestion.reference_match
                                                            ? 'bankStatements.match.suggestedReference'
                                                            : 'bankStatements.match.suggested',
                                                    )}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {transaction.cleared
                                                ? t(
                                                      'cashTransactions.clearedAt',
                                                      {
                                                          date: transaction.cleared_on,
                                                      },
                                                  )
                                                : t(
                                                      'cashTransactions.notCleared',
                                                  )}
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <Button
                                                size="sm"
                                                disabled={match.isPending}
                                                aria-label={t(
                                                    'bankStatements.match.choose',
                                                    {
                                                        date: transaction.transaction_date,
                                                        reference:
                                                            transaction.reference ??
                                                            '—',
                                                    },
                                                )}
                                                onClick={() => {
                                                    setFormError(null);
                                                    match.mutate(
                                                        transaction.id,
                                                    );
                                                }}
                                            >
                                                {t(
                                                    'bankStatements.match.action',
                                                )}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}
            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('common.cancel')}
                </Button>
            </DialogFooter>
        </div>
    );
}

/**
 * Matches one statement line to a cash transaction of the same account, direction and amount (SL-FR-13).
 * Transactions the API ranks as likely (by reference and closeness of the date) come first; any other
 * transaction with the same amount can be chosen by hand. The match clears the transaction.
 */
export function BankMatchDialog({
    companyId,
    statementId,
    line,
    onOpenChange,
}: {
    companyId: string;
    statementId: string;
    line: BankStatementLine | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={line !== null}
            onOpenChange={onOpenChange}
            title={t('bankStatements.match.title')}
            description={t('bankStatements.match.description')}
            size="lg"
        >
            {line !== null && (
                <Candidates
                    key={line.id}
                    companyId={companyId}
                    statementId={statementId}
                    line={line}
                    onDone={() => onOpenChange(false)}
                />
            )}
        </FormDialog>
    );
}
