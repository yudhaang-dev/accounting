import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useLastDefined } from '@/hooks/use-last-defined';
import { formatDecimal } from '@/lib/decimal';
import { actionErrorMessage } from '@/lib/errors';
import {
    waiveReconciliation,
    type Reconciliation,
} from '@/modules/subledger/reconciliations';

/** The journals that explain a difference, with a link to each (SL-FR-09). */
export function CausesDialog({
    companyId,
    reconciliation,
    canReadJournals,
    onClose,
}: {
    companyId: string;
    reconciliation: Reconciliation | null;
    canReadJournals: boolean;
    onClose: () => void;
}) {
    const { t, i18n } = useTranslation();
    const shown = useLastDefined(reconciliation);

    return (
        <FormDialog
            open={reconciliation !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={t('reconciliations.causesTitle', {
                account: shown?.account_code ?? '',
            })}
            description={t('reconciliations.causesDescription')}
        >
            {shown &&
                (shown.causes.length === 0 ? (
                    <p className="text-sm">{t('reconciliations.noCauses')}</p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('reconciliations.journal')}
                                    </TableHead>
                                    <TableHead>
                                        {t('reconciliations.date')}
                                    </TableHead>
                                    <TableHead>
                                        {t('reconciliations.source')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('reconciliations.debit')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('reconciliations.credit')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shown.causes.map((cause) => (
                                    <TableRow key={cause.journal_id}>
                                        <TableCell className="font-medium">
                                            {canReadJournals ? (
                                                <Link
                                                    to="/w/$companyId/journals/$journalId"
                                                    params={{
                                                        companyId,
                                                        journalId:
                                                            cause.journal_id,
                                                    }}
                                                    className="underline underline-offset-2"
                                                >
                                                    {cause.number}
                                                </Link>
                                            ) : (
                                                cause.number
                                            )}
                                            {cause.description && (
                                                <div className="text-xs font-normal text-muted-foreground">
                                                    {cause.description}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {cause.accounting_date}
                                        </TableCell>
                                        <TableCell>{cause.source}</TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {formatDecimal(
                                                cause.debit,
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {formatDecimal(
                                                cause.credit,
                                                i18n.language,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                ))}
        </FormDialog>
    );
}

function WaiveForm({
    companyId,
    reconciliation,
    onDone,
}: {
    companyId: string;
    reconciliation: Reconciliation;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [invalid, setInvalid] = useState(false);

    const waive = useMutation({
        mutationFn: () =>
            waiveReconciliation(companyId, reconciliation.id, reason.trim()),
        onSuccess: async () => {
            await Promise.all(
                ['reconciliations', 'fiscal-years'].map((key) =>
                    queryClient.invalidateQueries({
                        queryKey: ['companies', companyId, key],
                    }),
                ),
            );
            toast.success(t('reconciliations.waived'));
            onDone();
        },
        onError: (failure) => setError(actionErrorMessage(failure, t)),
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setError(null);

                if (reason.trim() === '') {
                    setInvalid(true);

                    return;
                }

                setInvalid(false);
                waive.mutate();
            }}
        >
            <FieldGroup>
                {error && <FormAlert>{error}</FormAlert>}
                <Field data-invalid={invalid ? true : undefined}>
                    <FieldLabel htmlFor="waive-reason">
                        {t('reconciliations.reason')}
                    </FieldLabel>
                    <Textarea
                        id="waive-reason"
                        rows={3}
                        maxLength={500}
                        autoFocus
                        value={reason}
                        aria-invalid={invalid ? true : undefined}
                        onChange={(event) => setReason(event.target.value)}
                    />
                    {invalid && <FieldError>{t('common.required')}</FieldError>}
                </Field>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={waive.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('reconciliations.waiveSubmit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Waives a difference with a mandatory reason, so that it stops blocking the year closing (SL-FR-09). */
export function WaiveDialog({
    companyId,
    reconciliation,
    onClose,
}: {
    companyId: string;
    reconciliation: Reconciliation | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const shown = useLastDefined(reconciliation);

    return (
        <FormDialog
            open={reconciliation !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={t('reconciliations.waiveTitle', {
                account: shown?.account_code ?? '',
            })}
            description={t('reconciliations.waiveDescription')}
        >
            {shown && (
                <WaiveForm
                    key={shown.id}
                    companyId={companyId}
                    reconciliation={shown}
                    onDone={onClose}
                />
            )}
        </FormDialog>
    );
}
