import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import {
    ProposeAdjustmentDialog,
    RejectAdjustmentDialog,
} from '@/modules/consolidation/components/adjustment-dialogs';
import {
    adjustmentsQuery,
    approveAdjustment,
    groupCan,
    type Adjustment,
} from '@/modules/consolidation/groups';
import { useGroup } from '@/modules/consolidation/hooks/use-group';
import { meQuery } from '@/modules/identity-access/session';

const STATUS_VARIANT = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
} as const;

/**
 * The manual adjustments of a run (CO-FR-08): each with its source, reason and lines. Only approved ones are
 * part of the result above; whoever proposed one cannot approve it, and an adjustment never reaches the ledger
 * of an entity (CO-AC-04).
 */
export function AdjustmentsSection({
    groupId,
    runId,
    currency,
}: {
    groupId: string;
    runId: string;
    currency: string;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const group = useGroup(groupId);
    const me = useQuery(meQuery).data;
    const adjustments = useQuery(adjustmentsQuery(groupId, runId));
    const [proposing, setProposing] = useState(false);
    const [approving, setApproving] = useState<Adjustment | null>(null);
    const [rejecting, setRejecting] = useState<Adjustment | null>(null);
    const [approveError, setApproveError] = useState<string | null>(null);
    const canRun = groupCan(group, 'run');
    const canApprove = groupCan(group, 'approve');
    const money = (value: string) => formatMoney(value, i18n.language);

    const approve = useMutation({
        mutationFn: (adjustment: Adjustment) =>
            approveAdjustment(groupId, adjustment.id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'runs', runId],
            });
            toast.success(t('consolidation.adjustments.approved'));
            setApproving(null);
        },
        onError: (error) => setApproveError(actionErrorMessage(error, t)),
    });

    return (
        <section
            className="flex flex-col gap-3"
            aria-labelledby="run-adjustments"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 id="run-adjustments" className="text-sm font-medium">
                    {t('consolidation.adjustments.title')}
                </h3>
                {canRun && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setProposing(true)}
                    >
                        <PlusIcon data-icon="inline-start" />
                        {t('consolidation.adjustments.new')}
                    </Button>
                )}
            </div>
            <p className="max-w-2xl text-sm text-muted-foreground">
                {t('consolidation.adjustments.description')}
            </p>

            {adjustments.isError ? (
                <FormAlert>
                    {genericErrorMessage(adjustments.error, t)}
                </FormAlert>
            ) : adjustments.data === undefined ? (
                <Skeleton className="h-16 w-full" />
            ) : adjustments.data.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('consolidation.adjustments.empty')}
                </p>
            ) : (
                adjustments.data.map((adjustment) => {
                    const own = me?.id === adjustment.proposed_by;

                    return (
                        <article
                            key={adjustment.id}
                            className="flex flex-col gap-2 rounded-lg border p-3"
                            aria-label={adjustment.source}
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {adjustment.source}
                                </span>
                                <Badge
                                    variant={
                                        STATUS_VARIANT[
                                            adjustment.status as keyof typeof STATUS_VARIANT
                                        ] ?? 'outline'
                                    }
                                >
                                    {t(
                                        `consolidation.adjustments.statuses.${adjustment.status as 'pending'}`,
                                    )}
                                </Badge>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {adjustment.reason}
                            </p>
                            {adjustment.rejection_reason && (
                                <p className="text-sm text-destructive">
                                    {t(
                                        'consolidation.adjustments.rejectedFor',
                                        {
                                            reason: adjustment.rejection_reason,
                                        },
                                    )}
                                </p>
                            )}
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'consolidation.accounts.code',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'consolidation.accounts.name',
                                                )}
                                            </TableHead>
                                            <TableHead className="text-end">
                                                {t(
                                                    'consolidation.runPage.debit',
                                                )}
                                            </TableHead>
                                            <TableHead className="text-end">
                                                {t(
                                                    'consolidation.runPage.credit',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {adjustment.lines.map((line, index) => (
                                            <TableRow
                                                key={`${line.group_account_id}:${index}`}
                                            >
                                                <TableCell className="font-mono">
                                                    {line.code}
                                                </TableCell>
                                                <TableCell>
                                                    {line.name}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums">
                                                    {money(line.debit)}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums">
                                                    {money(line.credit)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            {adjustment.status === 'pending' && canApprove && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        size="sm"
                                        disabled={own}
                                        onClick={() => {
                                            setApproveError(null);
                                            setApproving(adjustment);
                                        }}
                                    >
                                        {t('consolidation.adjustments.approve')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={own}
                                        onClick={() => setRejecting(adjustment)}
                                    >
                                        {t('consolidation.adjustments.reject')}
                                    </Button>
                                    {own && (
                                        <span className="text-sm text-muted-foreground">
                                            {t(
                                                'consolidation.adjustments.ownHint',
                                            )}
                                        </span>
                                    )}
                                </div>
                            )}
                        </article>
                    );
                })
            )}

            <ProposeAdjustmentDialog
                groupId={groupId}
                runId={runId}
                currency={currency}
                open={proposing}
                onOpenChange={setProposing}
            />
            <RejectAdjustmentDialog
                groupId={groupId}
                adjustment={rejecting}
                onOpenChange={(open) => {
                    if (!open) {
                        setRejecting(null);
                    }
                }}
            />
            <ConfirmDialog
                open={approving !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setApproving(null);
                    }
                }}
                title={t('consolidation.adjustments.approveTitle')}
                description={t('consolidation.adjustments.approveDescription')}
                confirmLabel={t('consolidation.adjustments.approve')}
                pending={approve.isPending}
                error={approveError}
                onConfirm={() => approving && approve.mutate(approving)}
            />
        </section>
    );
}
