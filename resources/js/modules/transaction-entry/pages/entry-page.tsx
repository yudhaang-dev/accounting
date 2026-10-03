import {
    useMutation,
    useQuery,
    useQueryClient,
    useSuspenseQuery,
} from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLastDefined } from '@/hooks/use-last-defined';
import { DimensionTags, dimensionTags } from '@/components/dimension-tags';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatDecimal, isPositive, sumDecimals } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { useTypeLabel } from '@/modules/transaction-entry/type-labels';
import { EntryStatusBadge } from '@/modules/transaction-entry/components/entry-status-badge';
import { AttachmentsCard } from '@/modules/transaction-entry/components/attachments-card';
import { EntryValuesCard } from '@/modules/transaction-entry/components/entry-values-card';
import { ReverseJournalButton } from '@/modules/transaction-entry/components/reverse-journal-button';
import { ReasonDialog } from '@/modules/transaction-entry/components/reason-dialog';
import {
    accountNamesQuery,
    dimensionNamesQuery,
    entryQuery,
    runEntryAction,
    type EntryAction,
} from '@/modules/transaction-entry/entries';

export type EntryPermissions = {
    /** Edit, revise and cancel all belong to whoever may create entries. */
    create: boolean;
    submit: boolean;
    approve: boolean;
    reject: boolean;
    post: boolean;
    /** Reverses the posted journal of the entry. */
    reverse: boolean;
    /** Links the posted journal. */
    journals: boolean;
    /** Names the dimensions of the lines instead of leaving them out. */
    dimensions: boolean;
};

/** One entry with its lines, approval trail and the lifecycle actions the user may take. */
export function EntryPage({
    companyId,
    entryId,
    currentUserId,
    can,
}: {
    companyId: string;
    entryId: string;
    currentUserId: number;
    can: EntryPermissions;
}) {
    const { t, i18n } = useTranslation();
    const typeLabel = useTypeLabel(companyId);
    const queryClient = useQueryClient();
    const { data: entry } = useSuspenseQuery(entryQuery(companyId, entryId));
    const accounts = useQuery(accountNamesQuery(companyId));
    const dimensionNames = useQuery({
        ...dimensionNamesQuery(companyId),
        enabled:
            can.dimensions &&
            entry.lines.some((line) => Object.keys(line.dimensions).length > 0),
    });
    const [reasonMode, setReasonMode] = useState<'reject' | 'cancel' | null>(
        null,
    );
    const [actionError, setActionError] = useState<string | null>(null);
    // Approving and posting cannot be taken back, so they ask first.
    const [confirming, setConfirming] = useState<'approve' | 'post' | null>(
        null,
    );
    // `asking` keeps the wording while the dialog fades out after `confirming` is cleared.
    const asking = useLastDefined(confirming) ?? 'post';

    useDocumentTitle(
        t('entries.detail.title', {
            number: entry.number ?? t('entries.unnumbered'),
        }),
    );

    const act = useMutation({
        mutationFn: (action: Exclude<EntryAction, 'reject' | 'cancel'>) =>
            runEntryAction(companyId, entry.id, action),
        onSuccess: async (_, action) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'entries'],
            });
            toast.success(t(`entries.detail.done.${action}`));
        },
        onSettled: () => setConfirming(null),
        // The server says why in the user's language: the entry is not balanced, the period is
        // closed, the approver may not be the author, and so on.
        onError: (error) =>
            setActionError(
                isApiError(error) && error.status !== 0
                    ? [
                          error.message,
                          ...Object.values(error.errors).flat(),
                      ].join(' ')
                    : genericErrorMessage(error, t),
            ),
    });

    const run = (action: Exclude<EntryAction, 'reject' | 'cancel'>) => {
        setActionError(null);
        act.mutate(action);
    };

    const debit = sumDecimals(entry.lines.map((line) => line.debit));
    const credit = sumDecimals(entry.lines.map((line) => line.credit));
    const who = (id: number | null) =>
        id === null
            ? t('entries.detail.client')
            : id === currentUserId
              ? t('entries.detail.you')
              : t('entries.detail.user', { id });
    const busy = act.isPending;

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('entries.detail.title', {
                            number: entry.number ?? t('entries.unnumbered'),
                        })}
                    </h1>
                    <EntryStatusBadge status={entry.status} />
                </div>

                <div className="flex flex-wrap gap-2">
                    {entry.status === 'draft' && (
                        <>
                            {can.create && (
                                <Button
                                    variant="outline"
                                    render={
                                        <Link
                                            to="/w/$companyId/entries/$entryId/edit"
                                            params={{ companyId, entryId }}
                                        />
                                    }
                                >
                                    {t('entries.detail.actions.edit')}
                                </Button>
                            )}
                            {can.submit && (
                                <Button
                                    disabled={busy}
                                    onClick={() => run('submit')}
                                >
                                    {t('entries.detail.actions.submit')}
                                </Button>
                            )}
                        </>
                    )}
                    {entry.status === 'submitted' && (
                        <>
                            {can.reject && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => setReasonMode('reject')}
                                >
                                    {t('entries.detail.actions.reject')}
                                </Button>
                            )}
                            {can.approve && (
                                <Button
                                    disabled={busy}
                                    onClick={() => setConfirming('approve')}
                                >
                                    {t('entries.detail.actions.approve')}
                                </Button>
                            )}
                        </>
                    )}
                    {entry.status === 'approved' && can.post && (
                        <Button
                            disabled={busy}
                            onClick={() => setConfirming('post')}
                        >
                            {t('entries.detail.actions.post')}
                        </Button>
                    )}
                    {entry.status === 'rejected' && can.create && (
                        <Button disabled={busy} onClick={() => run('revise')}>
                            {t('entries.detail.actions.revise')}
                        </Button>
                    )}
                    {entry.status === 'posted' &&
                        entry.journal_id &&
                        can.reverse && (
                            <ReverseJournalButton
                                companyId={companyId}
                                journalId={entry.journal_id}
                                journalNumber={
                                    entry.journal_number ?? entry.number ?? ''
                                }
                            />
                        )}
                    {entry.status === 'posted' &&
                        can.create &&
                        !entry.reverses_journal_id && (
                            <Button
                                variant="outline"
                                render={
                                    <Link
                                        to="/w/$companyId/entries/new"
                                        params={{ companyId }}
                                        search={{ replaces: entry.id }}
                                    />
                                }
                            >
                                {t('entries.detail.actions.replace')}
                            </Button>
                        )}
                    {(entry.status === 'draft' ||
                        entry.status === 'rejected') &&
                        can.create && (
                            <Button
                                variant="ghost"
                                disabled={busy}
                                onClick={() => setReasonMode('cancel')}
                            >
                                {t('entries.detail.actions.cancel')}
                            </Button>
                        )}
                </div>
            </div>

            {actionError && <FormAlert>{actionError}</FormAlert>}

            {entry.auto_reverse_on &&
                entry.auto_reversal_status === 'failed' && (
                    <Alert variant="destructive" role="alert">
                        <AlertTitle>
                            {t('entries.detail.autoReversalFailedTitle', {
                                date: entry.auto_reverse_on,
                            })}
                        </AlertTitle>
                        <AlertDescription className="flex flex-col items-start gap-2">
                            <span>
                                {entry.auto_reversal_error ??
                                    t(
                                        'entries.detail.autoReversalFailedUnknown',
                                    )}
                            </span>
                            <span>
                                {t('entries.detail.autoReversalFailedHint')}
                            </span>
                            {can.reverse && entry.journal_id && (
                                <ReverseJournalButton
                                    companyId={companyId}
                                    journalId={entry.journal_id}
                                    journalNumber={
                                        entry.journal_number ??
                                        entry.number ??
                                        ''
                                    }
                                />
                            )}
                        </AlertDescription>
                    </Alert>
                )}
            {entry.auto_reverse_on &&
                entry.auto_reversal_status !== 'failed' && (
                    <Alert>
                        <AlertDescription>
                            {entry.auto_reversal_status === 'done' &&
                            entry.auto_reversal_entry_id ? (
                                <>
                                    {t('entries.detail.autoReversalDone', {
                                        date: entry.auto_reverse_on,
                                    })}{' '}
                                    <Link
                                        to="/w/$companyId/entries/$entryId"
                                        params={{
                                            companyId,
                                            entryId:
                                                entry.auto_reversal_entry_id,
                                        }}
                                        className="underline underline-offset-4"
                                    >
                                        {t('entries.detail.autoReversalOpen')}
                                    </Link>
                                </>
                            ) : (
                                t('entries.detail.autoReversalPending', {
                                    date: entry.auto_reverse_on,
                                })
                            )}
                        </AlertDescription>
                    </Alert>
                )}

            <Card>
                <CardContent>
                    <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                        <div className="flex flex-col gap-0.5">
                            <dt className="text-muted-foreground">
                                {t('entries.detail.type')}
                            </dt>
                            <dd className="font-medium">
                                {typeLabel(entry.transaction_type_key)}
                            </dd>
                        </div>
                        <div className="flex flex-col gap-0.5">
                            <dt className="text-muted-foreground">
                                {t('entries.detail.accountingDate')}
                            </dt>
                            <dd className="font-medium">
                                {entry.accounting_date}
                            </dd>
                        </div>
                        {entry.document_date && (
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-muted-foreground">
                                    {t('entries.detail.documentDate')}
                                </dt>
                                <dd className="font-medium">
                                    {entry.document_date}
                                </dd>
                            </div>
                        )}
                        {entry.journal_number && (
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-muted-foreground">
                                    {t('entries.detail.journal')}
                                </dt>
                                <dd className="font-medium">
                                    {entry.journal_id && can.journals ? (
                                        <Link
                                            to="/w/$companyId/journals/$journalId"
                                            params={{
                                                companyId,
                                                journalId: entry.journal_id,
                                            }}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {t('entries.detail.journalPosted', {
                                                number: entry.journal_number,
                                            })}
                                        </Link>
                                    ) : (
                                        t('entries.detail.journalPosted', {
                                            number: entry.journal_number,
                                        })
                                    )}
                                </dd>
                            </div>
                        )}
                        {entry.reverses_journal_id && (
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-muted-foreground">
                                    {t('entries.detail.reverses')}
                                </dt>
                                <dd className="font-medium">
                                    {can.journals ? (
                                        <Link
                                            to="/w/$companyId/journals/$journalId"
                                            params={{
                                                companyId,
                                                journalId:
                                                    entry.reverses_journal_id,
                                            }}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {t('entries.detail.openJournal')}
                                        </Link>
                                    ) : (
                                        t('entries.detail.openJournal')
                                    )}
                                    {entry.reason ? ' · ' + entry.reason : ''}
                                </dd>
                            </div>
                        )}
                        {entry.replaces_entry_id && (
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-muted-foreground">
                                    {t('entries.detail.replaces')}
                                </dt>
                                <dd className="font-medium">
                                    <Link
                                        to="/w/$companyId/entries/$entryId"
                                        params={{
                                            companyId,
                                            entryId: entry.replaces_entry_id,
                                        }}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {t('entries.detail.openEntry')}
                                    </Link>
                                </dd>
                            </div>
                        )}
                        <div className="flex flex-col gap-0.5 sm:col-span-2">
                            <dt className="text-muted-foreground">
                                {t('entries.detail.description')}
                            </dt>
                            <dd className="font-medium">{entry.description}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <EntryValuesCard
                companyId={companyId}
                entry={entry}
                canReadDimensions={can.dimensions}
            />

            <Card>
                <CardHeader>
                    <CardTitle>{t('entries.detail.lines')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('entries.detail.account')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('entries.detail.debit')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('entries.detail.credit')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entry.lines.map((line, index) => {
                                    const account =
                                        accounts.data?.[line.account_id];

                                    return (
                                        <TableRow key={index}>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span>
                                                        {account
                                                            ? `${account.code} · ${account.name}`
                                                            : accounts.data
                                                              ? t(
                                                                    'entries.detail.unknownAccount',
                                                                )
                                                              : '…'}
                                                    </span>
                                                    {line.description && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {line.description}
                                                        </span>
                                                    )}
                                                    <DimensionTags
                                                        tags={dimensionTags(
                                                            line.dimensions,
                                                            dimensionNames.data,
                                                        )}
                                                    />
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {isPositive(line.debit)
                                                    ? formatDecimal(
                                                          line.debit,
                                                          i18n.language,
                                                      )
                                                    : ''}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {isPositive(line.credit)
                                                    ? formatDecimal(
                                                          line.credit,
                                                          i18n.language,
                                                      )
                                                    : ''}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell className="font-medium">
                                        {t('entries.detail.total')}
                                    </TableCell>
                                    <TableCell className="text-end font-medium tabular-nums">
                                        {formatDecimal(debit, i18n.language)}
                                    </TableCell>
                                    <TableCell className="text-end font-medium tabular-nums">
                                        {formatDecimal(credit, i18n.language)}
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            <AttachmentsCard
                companyId={companyId}
                entry={entry}
                canChange={can.create}
            />

            <Card>
                <CardHeader>
                    <CardTitle>{t('entries.detail.trail')}</CardTitle>
                </CardHeader>
                <CardContent>
                    {!entry.decisions || entry.decisions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('entries.detail.noTrail')}
                        </p>
                    ) : (
                        <ol className="flex flex-col gap-3 text-sm">
                            {entry.decisions.map((decision, index) => (
                                <li
                                    key={index}
                                    className="flex flex-col gap-0.5"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {t(
                                                `entries.detail.decisions.${decision.action}`,
                                                {
                                                    defaultValue:
                                                        decision.action,
                                                },
                                            )}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            · {who(decision.actor_id)} ·{' '}
                                            {new Date(
                                                decision.at,
                                            ).toLocaleString(i18n.language)}
                                        </span>
                                    </span>
                                    {decision.reason && (
                                        <span className="text-muted-foreground">
                                            {t('entries.detail.reason', {
                                                reason: decision.reason,
                                            })}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </CardContent>
            </Card>

            <ConfirmDialog
                open={confirming !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirming(null);
                    }
                }}
                title={t(`confirm.${asking}.title`, {
                    number: entry.number ?? t('entries.unnumbered'),
                })}
                description={t(`confirm.${asking}.description`)}
                confirmLabel={t(`confirm.${asking}.action`)}
                pending={act.isPending}
                onConfirm={() => confirming && run(confirming)}
            />
            <ReasonDialog
                companyId={companyId}
                entry={entry}
                mode={reasonMode}
                onClose={() => setReasonMode(null)}
            />
        </>
    );
}
