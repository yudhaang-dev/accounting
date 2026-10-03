import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { InboxIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useLastDefined } from '@/hooks/use-last-defined';
import { formatDecimal, sumDecimals } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { ReasonDialog } from '@/modules/transaction-entry/components/reason-dialog';
import {
    canDecide,
    nextStage,
    runEntryAction,
    submittedEntriesQuery,
    type Entry,
} from '@/modules/transaction-entry/entries';
import { useTypeLabel } from '@/modules/transaction-entry/type-labels';

type Scope = 'mine' | 'all';

/**
 * Entries waiting for an approval (TE-FR-09), by default only those whose next stage the user can take.
 * Each stage names the permission it needs, so an entry is shown to whoever may approve it next.
 */
export function ApprovalsPage({
    companyId,
    permissions,
    userId,
}: {
    companyId: string;
    permissions: string[];
    userId: number;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const typeLabel = useTypeLabel(companyId);
    const [scope, setScope] = useState<Scope>('mine');
    const [approving, setApproving] = useState<Entry | null>(null);
    const [rejecting, setRejecting] = useState<Entry | null>(null);
    const [error, setError] = useState<string | null>(null);
    const asking = useLastDefined(approving);
    const rejected = useLastDefined(rejecting);

    useDocumentTitle(t('approvals.pageTitle'));

    const inbox = useQuery(submittedEntriesQuery(companyId));
    const shown =
        scope === 'mine'
            ? (inbox.data ?? []).filter((entry) =>
                  canDecide(entry, permissions, userId),
              )
            : (inbox.data ?? []);

    const approve = useMutation({
        mutationFn: (entry: Entry) =>
            runEntryAction(companyId, entry.id, 'approve'),
        onSuccess: async (entry) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'entries'],
            });
            toast.success(t('approvals.approved', { number: entry.number }));
        },
        // The server says why in the user's language: the entry was changed, the approver may not be its author.
        onError: (failure) => setError(actionErrorMessage(failure, t)),
        onSettled: () => setApproving(null),
    });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('approvals.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('approvals.description')}
                    </p>
                </div>
                <ToggleGroup
                    variant="outline"
                    aria-label={t('approvals.scope')}
                    value={[scope]}
                    onValueChange={(next) => {
                        const picked = next[0];

                        if (picked === 'mine' || picked === 'all') {
                            setScope(picked);
                        }
                    }}
                >
                    <ToggleGroupItem value="mine">
                        {t('approvals.mine')}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="all">
                        {t('approvals.all')}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            {error && <FormAlert>{error}</FormAlert>}

            {inbox.isError ? (
                <FormAlert>{genericErrorMessage(inbox.error, t)}</FormAlert>
            ) : inbox.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : shown.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <InboxIcon />
                        </EmptyMedia>
                        <EmptyTitle>
                            {t(
                                scope === 'mine'
                                    ? 'approvals.emptyMine'
                                    : 'approvals.emptyAll',
                            )}
                        </EmptyTitle>
                        <EmptyDescription>
                            {t(
                                scope === 'mine'
                                    ? 'approvals.emptyMineDescription'
                                    : 'approvals.emptyAllDescription',
                            )}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('entries.number')}</TableHead>
                                <TableHead>{t('entries.type')}</TableHead>
                                <TableHead>{t('entries.date')}</TableHead>
                                <TableHead className="text-end">
                                    {t('entries.amount')}
                                </TableHead>
                                <TableHead>{t('approvals.stage')}</TableHead>
                                <TableHead>
                                    <span className="sr-only">
                                        {t('approvals.actions')}
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.map((entry) => {
                                const mine = canDecide(
                                    entry,
                                    permissions,
                                    userId,
                                );
                                const stages =
                                    entry.approval?.stages.length ?? 1;
                                const done = entry.approval?.approved ?? 0;

                                return (
                                    <TableRow key={entry.id}>
                                        <TableCell className="font-medium">
                                            <Link
                                                to="/w/$companyId/entries/$entryId"
                                                params={{
                                                    companyId,
                                                    entryId: entry.id,
                                                }}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {entry.number}
                                            </Link>
                                            <div className="max-w-xs truncate text-xs font-normal text-muted-foreground">
                                                {entry.description}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {typeLabel(
                                                entry.transaction_type_key,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {entry.accounting_date}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {formatDecimal(
                                                sumDecimals(
                                                    entry.lines.map(
                                                        (line) => line.debit,
                                                    ),
                                                ),
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col gap-1">
                                                <span className="text-xs text-muted-foreground">
                                                    {t('approvals.stageOf', {
                                                        current: Math.min(
                                                            done + 1,
                                                            stages,
                                                        ),
                                                        total: stages,
                                                    })}
                                                </span>
                                                <Badge
                                                    variant="outline"
                                                    className="w-fit font-mono"
                                                >
                                                    {nextStage(entry)}
                                                </Badge>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <div className="flex justify-end gap-2">
                                                {mine &&
                                                    permissions.includes(
                                                        'entries.reject',
                                                    ) && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            aria-label={t(
                                                                'approvals.reject',
                                                                {
                                                                    number: entry.number,
                                                                },
                                                            )}
                                                            onClick={() =>
                                                                setRejecting(
                                                                    entry,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'entries.detail.actions.reject',
                                                            )}
                                                        </Button>
                                                    )}
                                                {mine && (
                                                    <Button
                                                        size="sm"
                                                        aria-label={t(
                                                            'approvals.approve',
                                                            {
                                                                number: entry.number,
                                                            },
                                                        )}
                                                        onClick={() => {
                                                            setError(null);
                                                            setApproving(entry);
                                                        }}
                                                    >
                                                        {t(
                                                            'entries.detail.actions.approve',
                                                        )}
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}

            <ConfirmDialog
                open={approving !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setApproving(null);
                    }
                }}
                title={t('confirm.approve.title', {
                    number: asking?.number ?? '',
                })}
                description={t('confirm.approve.description')}
                confirmLabel={t('confirm.approve.action')}
                pending={approve.isPending}
                onConfirm={() => approving && approve.mutate(approving)}
            />
            {rejected && (
                <ReasonDialog
                    companyId={companyId}
                    entry={rejected}
                    mode={rejecting ? 'reject' : null}
                    onClose={() => setRejecting(null)}
                />
            )}
        </>
    );
}
