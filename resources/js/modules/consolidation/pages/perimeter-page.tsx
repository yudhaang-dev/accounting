import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckIcon, LogOutIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
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
import { formatIsoDate } from '@/lib/date';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import {
    EndMemberDialog,
    ProposeMemberDialog,
} from '@/modules/consolidation/components/member-dialogs';
import {
    approveMember,
    groupCan,
    perimeterQuery,
    type PerimeterMember,
} from '@/modules/consolidation/groups';
import { useGroup } from '@/modules/consolidation/hooks/use-group';
import { can } from '@/modules/identity-access/permissions';
import type { Workspace } from '@/modules/identity-access/workspaces';

const STATUS_VARIANT = {
    pending: 'secondary',
    approved: 'default',
    ended: 'outline',
} as const;

/**
 * The perimeter of a group (CO-FR-02): who is in it, and the reasoning of the assessment of control behind each
 * member. A proposed member counts only after someone with the approval ability, other than the proposer,
 * approves it; control is never inferred from memberships.
 */
export function PerimeterPage({
    groupId,
    workspaces,
}: {
    groupId: string;
    workspaces: Workspace[];
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const group = useGroup(groupId);
    const members = useQuery(perimeterQuery(groupId));
    const [proposing, setProposing] = useState(false);
    const [ending, setEnding] = useState<PerimeterMember | null>(null);
    const [approveError, setApproveError] = useState<string | null>(null);

    const canRun = groupCan(group, 'run');
    const canApprove = groupCan(group, 'approve');
    const taken = new Set(
        (members.data ?? [])
            .filter((member) => member.status !== 'ended')
            .map((member) => member.company_id),
    );
    // A company is offered only where the user may give its consent, and is not in the perimeter already.
    const candidates = workspaces.filter(
        (workspace) =>
            can(workspace, 'consolidation.join') &&
            !taken.has(workspace.company_id),
    );

    const approve = useMutation({
        mutationFn: (member: PerimeterMember) =>
            approveMember(groupId, member.id),
        onSuccess: async () => {
            setApproveError(null);
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'perimeter'],
            });
            toast.success(t('consolidation.perimeter.approved'));
        },
        onError: (error) => setApproveError(actionErrorMessage(error, t)),
    });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('consolidation.perimeter.description')}
                </p>
                {canRun && (
                    <Button
                        onClick={() => setProposing(true)}
                        disabled={candidates.length === 0}
                        title={
                            candidates.length === 0
                                ? t('consolidation.perimeter.noCandidates')
                                : undefined
                        }
                    >
                        <PlusIcon data-icon="inline-start" />
                        {t('consolidation.perimeter.propose')}
                    </Button>
                )}
            </div>

            {approveError && <FormAlert>{approveError}</FormAlert>}

            {members.isError ? (
                <FormAlert>{genericErrorMessage(members.error, t)}</FormAlert>
            ) : members.data === undefined ? (
                <Skeleton className="h-32 w-full" />
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('consolidation.perimeter.company')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.perimeter.status')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.perimeter.period')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.perimeter.controlBasis')}
                                </TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.data.map((member) => {
                                const parent =
                                    member.company_id ===
                                    group?.parent_company_id;

                                return (
                                    <TableRow key={member.id}>
                                        <TableCell className="font-medium">
                                            {member.company_name}
                                            {parent && (
                                                <Badge
                                                    variant="outline"
                                                    className="ms-2"
                                                >
                                                    {t(
                                                        'consolidation.perimeter.parent',
                                                    )}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    STATUS_VARIANT[
                                                        member.status as keyof typeof STATUS_VARIANT
                                                    ]
                                                }
                                            >
                                                {t(
                                                    `consolidation.perimeter.statuses.${member.status as 'pending' | 'approved' | 'ended'}`,
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatIsoDate(
                                                member.effective_from,
                                                i18n.language,
                                            )}
                                            {' – '}
                                            {member.effective_to
                                                ? formatIsoDate(
                                                      member.effective_to,
                                                      i18n.language,
                                                  )
                                                : t(
                                                      'consolidation.perimeter.open',
                                                  )}
                                        </TableCell>
                                        <TableCell className="max-w-md text-sm whitespace-normal text-muted-foreground">
                                            {member.control_basis}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-2">
                                                {canApprove &&
                                                    member.status ===
                                                        'pending' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                approve.isPending
                                                            }
                                                            onClick={() =>
                                                                approve.mutate(
                                                                    member,
                                                                )
                                                            }
                                                        >
                                                            <CheckIcon data-icon="inline-start" />
                                                            {t(
                                                                'consolidation.perimeter.approve',
                                                            )}
                                                        </Button>
                                                    )}
                                                {canRun &&
                                                    member.status !== 'ended' &&
                                                    !parent && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setEnding(
                                                                    member,
                                                                )
                                                            }
                                                        >
                                                            <LogOutIcon data-icon="inline-start" />
                                                            {t(
                                                                'consolidation.perimeter.end',
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

            <ProposeMemberDialog
                groupId={groupId}
                companies={candidates}
                open={proposing}
                onOpenChange={setProposing}
            />
            <EndMemberDialog
                groupId={groupId}
                member={ending}
                onOpenChange={(open) => {
                    if (!open) {
                        setEnding(null);
                    }
                }}
            />
        </>
    );
}
