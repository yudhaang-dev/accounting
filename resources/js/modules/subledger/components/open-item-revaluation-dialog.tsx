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
import { formatMoney, isPositive } from '@/lib/decimal';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import type { Period } from '@/modules/general-ledger/periods';
import { openItemNamesQuery } from '@/modules/subledger/allocations';
import {
    runOpenItemRevaluation,
    type OpenItemRevaluationRun,
} from '@/modules/subledger/open-item-revaluation';

/** What the user may open from the result. */
export type OpenItemRevaluationAccess = {
    openItems: boolean;
    journals: boolean;
};

function Result({
    companyId,
    run,
    access,
}: {
    companyId: string;
    run: OpenItemRevaluationRun;
    access: OpenItemRevaluationAccess;
}) {
    const { t, i18n } = useTranslation();
    const items = useQuery({
        ...openItemNamesQuery(companyId),
        enabled: access.openItems && run.adjustments.length > 0,
    });
    const names = new Map(
        (items.data ?? []).map((item) => [item.id, item.document_number]),
    );

    if (run.id === null) {
        return <p className="text-sm">{t('openItemRevaluation.nothing')}</p>;
    }

    return (
        <div className="flex flex-col gap-3">
            <p className="text-sm">
                {t('openItemRevaluation.posted', {
                    count: run.adjustments.length,
                })}
            </p>
            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>
                                {t('openItemRevaluation.document')}
                            </TableHead>
                            <TableHead>
                                {t('openItemRevaluation.currency')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('openItemRevaluation.remaining')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('openItemRevaluation.carried')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('openItemRevaluation.revalued')}
                            </TableHead>
                            <TableHead className="text-end">
                                {t('openItemRevaluation.difference')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {run.adjustments.map((row) => (
                            <TableRow key={row.open_item_id}>
                                <TableCell>
                                    {names.get(row.open_item_id) ??
                                        row.open_item_id}
                                </TableCell>
                                <TableCell>{row.currency}</TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {formatMoney(
                                        row.remaining_amount,
                                        i18n.language,
                                    )}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {formatMoney(row.carried, i18n.language)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {formatMoney(row.revalued, i18n.language)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {isPositive(row.adjustment) ? '+' : ''}
                                    {formatMoney(row.adjustment, i18n.language)}
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
                        {t('openItemRevaluation.openJournal')}
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
                            {t('openItemRevaluation.openReversal')}
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}

function Body({
    companyId,
    period,
    access,
    onDone,
}: {
    companyId: string;
    period: Period;
    access: OpenItemRevaluationAccess;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const [run, setRun] = useState<OpenItemRevaluationRun | null>(null);

    const revalue = useMutation({
        mutationFn: () => runOpenItemRevaluation(companyId, period.id),
        onSuccess: async (result) => {
            setRun(result);
            // A posted run changes balances, journals, and what the reconciliation compares.
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            });
        },
        onError: (error) => {
            setFormError(
                isApiError(error) && error.code === 'MAPPING_REQUIRED'
                    ? `${error.message} ${t('openItemRevaluation.mappingHint')}`
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
                        {t('openItemRevaluation.close')}
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
                    pendingLabel={t('openItemRevaluation.running')}
                >
                    {t('openItemRevaluation.run')}
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

/**
 * Revalues the foreign currency open items at the end of a period (SL-FR-07): shows what was
 * adjusted per document and links to the journal and its reversal. Running it again only posts what
 * changed since.
 */
export function OpenItemRevaluationDialog({
    companyId,
    period,
    access,
    onClose,
}: {
    companyId: string;
    period: Period | null;
    access: OpenItemRevaluationAccess;
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
                        {t('openItemRevaluation.title', {
                            name: shown?.name ?? '',
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('openItemRevaluation.description', {
                            date: shown?.end_date ?? '',
                        })}
                    </DialogDescription>
                </DialogHeader>
                {shown && (
                    <Body
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
