import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLastDefined } from '@/hooks/use-last-defined';
import { isPositive, formatDecimal } from '@/lib/decimal';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { allAccountsQuery } from '@/modules/general-ledger/accounts';
import type { Period } from '@/modules/general-ledger/periods';
import {
    runRevaluation,
    type RevaluationRun,
} from '@/modules/general-ledger/revaluation';

/** What the user may open from the result. */
export type RevaluationAccess = { accounts: boolean; journals: boolean };

function Result({
    companyId,
    run,
    access,
}: {
    companyId: string;
    run: RevaluationRun;
    access: RevaluationAccess;
}) {
    const { t, i18n } = useTranslation();
    const accounts = useQuery({
        ...allAccountsQuery(companyId),
        enabled: access.accounts && run.adjustments.length > 0,
    });
    const names = new Map(
        (accounts.data ?? []).map((account) => [
            account.id,
            `${account.code} · ${account.name}`,
        ]),
    );

    if (run.id === null) {
        return <p className="text-sm">{t('revaluation.nothing')}</p>;
    }

    return (
        <div className="flex flex-col gap-3">
            <p className="text-sm">
                {t('revaluation.posted', { count: run.adjustments.length })}
            </p>
            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('revaluation.account')}</TableHead>
                            <TableHead>{t('revaluation.currency')}</TableHead>
                            <TableHead className="text-end">
                                {t('revaluation.balance')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('revaluation.carried')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('revaluation.revalued')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('revaluation.difference')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {run.adjustments.map((row, index) => (
                            <TableRow
                                key={`${row.account_id}:${row.currency}:${index}`}
                            >
                                <TableCell>
                                    {names.get(row.account_id) ??
                                        row.account_id}
                                </TableCell>
                                <TableCell>{row.currency}</TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {formatDecimal(
                                        row.transaction_balance,
                                        i18n.language,
                                    )}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {formatDecimal(row.carried, i18n.language)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {formatDecimal(row.revalued, i18n.language)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {isPositive(row.adjustment) ? '+' : ''}
                                    {formatDecimal(
                                        row.adjustment,
                                        i18n.language,
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {access.journals && run.journal_id && (
                <div className="flex flex-wrap gap-4 text-sm">
                    <Link
                        to="/w/$companyId/journals/$journalId"
                        params={{ companyId, journalId: run.journal_id }}
                        className="underline underline-offset-4"
                    >
                        {t('revaluation.openJournal')}
                    </Link>
                    {run.reversal_journal_id && (
                        <Link
                            to="/w/$companyId/journals/$journalId"
                            params={{
                                companyId,
                                journalId: run.reversal_journal_id,
                            }}
                            className="underline underline-offset-4"
                        >
                            {t('revaluation.openReversal')}
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}

function RevaluationBody({
    companyId,
    period,
    access,
    onDone,
}: {
    companyId: string;
    period: Period;
    access: RevaluationAccess;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const [run, setRun] = useState<RevaluationRun | null>(null);

    const revalue = useMutation({
        mutationFn: () => runRevaluation(companyId, period.id),
        onSuccess: async (result) => {
            setRun(result);
            // A posted run changes balances and journals, and the periods keep no status of it.
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            });
        },
        onError: (error) => {
            setFormError(
                isApiError(error) && error.code === 'MAPPING_REQUIRED'
                    ? `${error.message} ${t('revaluation.mappingHint')}`
                    : actionErrorMessage(error, t),
            );
        },
    });

    if (run) {
        return (
            <>
                <Result companyId={companyId} run={run} access={access} />
                <DialogFooter>
                    <Button type="button" onClick={onDone}>
                        {t('revaluation.close')}
                    </Button>
                </DialogFooter>
            </>
        );
    }

    return (
        <form
            noValidate
            className="flex flex-col gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                revalue.mutate();
            }}
        >
            {formError && <FormAlert>{formError}</FormAlert>}
            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('common.cancel')}
                </Button>
                <SubmitButton
                    className="w-auto"
                    pending={revalue.isPending}
                    pendingLabel={t('revaluation.running')}
                >
                    {t('revaluation.run')}
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

/**
 * Revalues the foreign currency balances at the end of a period (GL-FR-09): shows what was adjusted
 * and links to the journal and its reversal. Running it again only posts what changed since.
 */
export function RevaluationDialog({
    companyId,
    period,
    access,
    onClose,
}: {
    companyId: string;
    period: Period | null;
    access: RevaluationAccess;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    // Keep the wording while the dialog fades out after `period` is cleared.
    const shown = useLastDefined(period);

    return (
        <Dialog
            open={period !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('revaluation.title', { name: shown?.name ?? '' })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('revaluation.description', {
                            date: shown?.end_date ?? '',
                        })}
                    </DialogDescription>
                </DialogHeader>
                {shown && (
                    <RevaluationBody
                        key={shown.id}
                        companyId={companyId}
                        period={shown}
                        access={access}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
