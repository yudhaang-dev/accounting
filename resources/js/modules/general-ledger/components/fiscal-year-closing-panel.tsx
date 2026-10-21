import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { CircleCheckIcon, CircleXIcon } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
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
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { formatDecimal } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    closeFiscalYear,
    closingStatusQuery,
    reopenFiscalYear,
} from '@/modules/general-ledger/fiscal-year-closing';

type Year = { id: string; name: string; end_date: string };

function Check({ ok, children }: { ok: boolean; children: React.ReactNode }) {
    const { t } = useTranslation();

    return (
        <li className="flex items-start gap-2 text-sm">
            {ok ? (
                <CircleCheckIcon
                    className="mt-0.5 size-4 shrink-0 text-green-600"
                    aria-label={t('closing.checkOk')}
                />
            ) : (
                <CircleXIcon
                    className="mt-0.5 size-4 shrink-0 text-destructive"
                    aria-label={t('closing.checkFailed')}
                />
            )}
            <span>{children}</span>
        </li>
    );
}

function ReopenForm({
    companyId,
    year,
    onDone,
}: {
    companyId: string;
    year: Year;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<{ reason: string }>({ defaultValues: { reason: '' } });
    const {
        register,
        formState: { errors },
    } = form;

    const reopen = useMutation({
        mutationFn: ({ reason }: { reason: string }) =>
            reopenFiscalYear(companyId, year.id, reason.trim()),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            });
            toast.success(t('closing.reopened', { name: year.name }));
            onDone();
        },
        onError: (error) => {
            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error)
            ) {
                return;
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                reopen.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}
                <Field data-invalid={errors.reason ? true : undefined}>
                    <FieldLabel htmlFor="reopen-year-reason">
                        {t('periods.reason')}
                    </FieldLabel>
                    <Textarea
                        id="reopen-year-reason"
                        rows={3}
                        maxLength={500}
                        autoFocus
                        aria-invalid={errors.reason ? true : undefined}
                        {...register('reason', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.reason && (
                        <FieldError>{errors.reason.message}</FieldError>
                    )}
                </Field>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={reopen.isPending}
                        pendingLabel={t('periods.reopening')}
                    >
                        {t('closing.reopenSubmit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/**
 * Closing of the selected fiscal year (GL-FR-12, GL-FR-14): the checks that decide whether it can
 * close, the closing itself, and once closed the result and the way to reopen it. Whether the checks
 * pass is worked out by the server; this only shows it.
 */
export function FiscalYearClosingPanel({
    companyId,
    year,
    canClose,
    canReopen,
    canReadJournals,
    canReadEntries,
    canReadReconciliations,
}: {
    companyId: string;
    year: Year;
    canClose: boolean;
    canReopen: boolean;
    canReadJournals: boolean;
    canReadEntries: boolean;
    canReadReconciliations: boolean;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState(false);
    const [reopening, setReopening] = useState(false);
    const status = useQuery(closingStatusQuery(companyId, year.id));

    const close = useMutation({
        mutationFn: () => closeFiscalYear(companyId, year.id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            });
            toast.success(t('closing.closed', { name: year.name }));
        },
        onSettled: () => setConfirming(false),
        // The server says why: unfinished entries, a missing mapping, a period that refuses the journal.
        onError: (error) => toast.error(actionErrorMessage(error, t)),
    });

    if (status.isError) {
        return <FormAlert>{genericErrorMessage(status.error, t)}</FormAlert>;
    }

    if (status.data === undefined) {
        return <Skeleton className="h-32 w-full" />;
    }

    const { closing, checks, ready } = status.data;
    const result = closing?.result ?? '0';
    const profit = !result.startsWith('-');

    return (
        <section
            aria-label={t('closing.title', { name: year.name })}
            className="flex flex-col gap-3 rounded-lg border p-4"
        >
            <h2 className="text-base font-semibold">
                {t('closing.title', { name: year.name })}
            </h2>

            {closing ? (
                <>
                    <p className="text-sm">
                        {t('closing.isClosed', { name: year.name })}{' '}
                        {closing.journal_id
                            ? t(profit ? 'closing.profit' : 'closing.loss', {
                                  amount: formatDecimal(
                                      result.replace('-', ''),
                                      i18n.language,
                                  ),
                              })
                            : t('closing.noActivity')}
                    </p>
                    {canReadJournals && closing.journal_id && (
                        <Link
                            to="/w/$companyId/journals/$journalId"
                            params={{
                                companyId,
                                journalId: closing.journal_id,
                            }}
                            className="text-sm underline underline-offset-4"
                        >
                            {t('closing.openJournal', {
                                number: closing.journal_number ?? '',
                            })}
                        </Link>
                    )}
                    {canReopen && (
                        <div>
                            <Button
                                variant="outline"
                                onClick={() => setReopening(true)}
                            >
                                {t('closing.reopenAction')}
                            </Button>
                        </div>
                    )}
                </>
            ) : (
                <>
                    <ul className="flex flex-col gap-2">
                        <Check ok={checks.retained_earnings.ok}>
                            {checks.retained_earnings.ok ? (
                                t('closing.retainedOk')
                            ) : (
                                <>
                                    {t('closing.retainedMissing')}{' '}
                                    <Link
                                        to="/w/$companyId/ledger-settings"
                                        params={{ companyId }}
                                        className="underline underline-offset-4"
                                    >
                                        {t('closing.toSettings')}
                                    </Link>
                                </>
                            )}
                        </Check>
                        <Check ok={checks.last_period.ok}>
                            {checks.last_period.ok
                                ? t('closing.periodOk', {
                                      name: checks.last_period.name ?? '',
                                  })
                                : t(
                                      checks.last_period.status ===
                                          'soft_closed'
                                          ? 'closing.periodSoftClosed'
                                          : 'closing.periodClosed',
                                      { name: checks.last_period.name ?? '' },
                                  )}
                        </Check>
                        <Check ok={checks.unfinished_entries.ok}>
                            {checks.unfinished_entries.ok ? (
                                t('closing.entriesOk')
                            ) : (
                                <>
                                    {t('closing.entriesPending', {
                                        count: checks.unfinished_entries.count,
                                        date: year.end_date,
                                    })}{' '}
                                    {canReadEntries && (
                                        <Link
                                            to="/w/$companyId/entries"
                                            params={{ companyId }}
                                            className="underline underline-offset-4"
                                        >
                                            {t('closing.toEntries')}
                                        </Link>
                                    )}
                                </>
                            )}
                        </Check>
                        <Check ok={checks.reconciliation.ok}>
                            {checks.reconciliation.ok
                                ? t('closing.reconciliationOk')
                                : t('closing.reconciliationPending', {
                                      count: checks.reconciliation.count,
                                      date: year.end_date,
                                  })}{' '}
                            {!checks.reconciliation.ok &&
                                canReadReconciliations && (
                                    <Link
                                        to="/w/$companyId/reconciliations"
                                        params={{ companyId }}
                                        search={{ as_of: year.end_date }}
                                        className="underline underline-offset-4"
                                    >
                                        {t('closing.toReconciliations')}
                                    </Link>
                                )}
                        </Check>
                    </ul>
                    {canClose && (
                        <div>
                            <Button
                                disabled={!ready || close.isPending}
                                onClick={() => setConfirming(true)}
                            >
                                {t('closing.closeAction')}
                            </Button>
                        </div>
                    )}
                </>
            )}

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={t('confirm.closeYear.title', { name: year.name })}
                description={t('confirm.closeYear.description')}
                confirmLabel={t('confirm.closeYear.action')}
                pending={close.isPending}
                onConfirm={() => close.mutate()}
            />
            <Dialog open={reopening} onOpenChange={setReopening}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('closing.reopenTitle', { name: year.name })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('closing.reopenDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    {reopening && (
                        <ReopenForm
                            companyId={companyId}
                            year={year}
                            onDone={() => setReopening(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
