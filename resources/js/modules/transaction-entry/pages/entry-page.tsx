import {
    useMutation,
    useQuery,
    useQueryClient,
    useSuspenseQuery,
} from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ArrowLeftIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { Button } from '@/components/ui/button';
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
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatDecimal, isPositive, sumDecimals } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { EntryStatusBadge } from '@/modules/transaction-entry/components/entry-status-badge';
import { ReasonDialog } from '@/modules/transaction-entry/components/reason-dialog';
import {
    accountNamesQuery,
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
    /** Links the posted journal. */
    journals: boolean;
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
    const queryClient = useQueryClient();
    const { data: entry } = useSuspenseQuery(entryQuery(companyId, entryId));
    const accounts = useQuery(accountNamesQuery(companyId));
    const [reasonMode, setReasonMode] = useState<'reject' | 'cancel' | null>(
        null,
    );
    const [actionError, setActionError] = useState<string | null>(null);

    useDocumentTitle(t('entries.detail.title', { number: entry.number }));

    const act = useMutation({
        mutationFn: (action: Exclude<EntryAction, 'reject' | 'cancel'>) =>
            runEntryAction(companyId, entry.id, action),
        onSuccess: async (_, action) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'entries'],
            });
            toast.success(t(`entries.detail.done.${action}`));
        },
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
    const who = (id: number) =>
        id === currentUserId
            ? t('entries.detail.you')
            : t('entries.detail.user', { id });
    const busy = act.isPending;

    return (
        <>
            <div>
                <Button
                    variant="ghost"
                    size="sm"
                    render={
                        <Link
                            to="/w/$companyId/entries"
                            params={{ companyId }}
                        />
                    }
                >
                    <ArrowLeftIcon data-icon="inline-start" />
                    {t('entries.detail.back')}
                </Button>
            </div>

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('entries.detail.title', { number: entry.number })}
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
                                    onClick={() => run('approve')}
                                >
                                    {t('entries.detail.actions.approve')}
                                </Button>
                            )}
                        </>
                    )}
                    {entry.status === 'approved' && can.post && (
                        <Button disabled={busy} onClick={() => run('post')}>
                            {t('entries.detail.actions.post')}
                        </Button>
                    )}
                    {entry.status === 'rejected' && can.create && (
                        <Button disabled={busy} onClick={() => run('revise')}>
                            {t('entries.detail.actions.revise')}
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

            <Card>
                <CardContent>
                    <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                        <div className="flex flex-col gap-0.5">
                            <dt className="text-muted-foreground">
                                {t('entries.detail.type')}
                            </dt>
                            <dd className="font-medium">
                                {entry.transaction_type_key}
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
                        <div className="flex flex-col gap-0.5 sm:col-span-2">
                            <dt className="text-muted-foreground">
                                {t('entries.detail.description')}
                            </dt>
                            <dd className="font-medium">{entry.description}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

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

            <ReasonDialog
                companyId={companyId}
                entry={entry}
                mode={reasonMode}
                onClose={() => setReasonMode(null)}
            />
        </>
    );
}
