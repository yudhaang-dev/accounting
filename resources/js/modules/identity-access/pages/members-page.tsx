import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, UsersIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
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
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useLastDefined } from '@/hooks/use-last-defined';
import { formatIsoDate, toIsoDate } from '@/lib/date';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import {
    invitationsQuery,
    membershipsQuery,
    revokeInvitation,
    revokeMembership,
    rolesQuery,
    type Invitation,
    type Membership,
} from '@/modules/identity-access/access';
import { MemberDialog } from '@/modules/identity-access/components/member-dialog';

const dateOf = (iso: string | null, language: string): string =>
    iso ? formatIsoDate(toIsoDate(new Date(iso)), language) : '';

/**
 * Members of the workspace and the invitations waiting to be answered (IA-FR-01): who holds which
 * roles, until when, and within which data scope (IA-FR-03). Revoking stops access from the next
 * request; the audit trail stays.
 */
export function MembersPage({
    companyId,
    currentUserId,
    canManage,
    canReadRoles,
    canReadDimensions,
}: {
    companyId: string;
    currentUserId: number;
    canManage: boolean;
    canReadRoles: boolean;
    canReadDimensions: boolean;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [inviting, setInviting] = useState(false);
    const [editing, setEditing] = useState<Membership | null>(null);
    const [revokingMember, setRevokingMember] = useState<Membership | null>(
        null,
    );
    const [revokingInvitation, setRevokingInvitation] =
        useState<Invitation | null>(null);
    const askingMember = useLastDefined(revokingMember);
    const askingInvitation = useLastDefined(revokingInvitation);

    useDocumentTitle(t('members.pageTitle'));

    const members = useQuery(membershipsQuery(companyId));
    const invitations = useQuery(invitationsQuery(companyId));
    // Invitations name roles by id; the names come from the same list the invite form uses.
    const roles = useQuery({ ...rolesQuery(companyId), enabled: canReadRoles });
    const roleName = (id: string) =>
        roles.data?.find((role) => role.id === id)?.name ?? id;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId],
            predicate: (query) =>
                query.queryKey[2] === 'memberships' ||
                query.queryKey[2] === 'invitations',
        });

    const revokeMemberAccess = useMutation({
        mutationFn: (membership: Membership) =>
            revokeMembership(companyId, membership.id),
        onSuccess: async (membership) => {
            await refresh();
            toast.success(t('members.revoked', { name: membership.user.name }));
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setRevokingMember(null),
    });

    const withdraw = useMutation({
        mutationFn: (invitation: Invitation) =>
            revokeInvitation(companyId, invitation.id),
        onSuccess: async (invitation) => {
            await refresh();
            toast.success(
                t('members.invitations.withdrawn', { email: invitation.email }),
            );
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setRevokingInvitation(null),
    });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('members.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('members.description')}
                    </p>
                </div>
                {canManage && (
                    <Button onClick={() => setInviting(true)}>
                        <PlusIcon data-icon="inline-start" />
                        {t('members.invite')}
                    </Button>
                )}
            </div>

            {members.isError ? (
                <FormAlert>{genericErrorMessage(members.error, t)}</FormAlert>
            ) : members.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : members.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <UsersIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('members.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('members.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('members.member')}</TableHead>
                                <TableHead>{t('members.roles')}</TableHead>
                                <TableHead>
                                    {t('members.scope.title')}
                                </TableHead>
                                <TableHead>{t('members.expires')}</TableHead>
                                <TableHead>{t('members.status')}</TableHead>
                                {canManage && (
                                    <TableHead className="w-12">
                                        <span className="sr-only">
                                            {t('members.actions')}
                                        </span>
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.data.map((member) => {
                                const active = member.status === 'active';

                                return (
                                    <TableRow
                                        key={member.id}
                                        className={
                                            active
                                                ? undefined
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <span className="font-medium">
                                                    {member.user.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {member.user.email}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <span className="flex flex-wrap gap-1">
                                                {member.roles.map((role) => (
                                                    <Badge
                                                        key={role.id}
                                                        variant={
                                                            role.is_active
                                                                ? 'secondary'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {role.name}
                                                    </Badge>
                                                ))}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {member.data_scope.length === 0
                                                ? t(
                                                      'members.scope.unrestricted',
                                                  )
                                                : t(
                                                      'members.scope.restricted',
                                                      {
                                                          total: member
                                                              .data_scope
                                                              .length,
                                                      },
                                                  )}
                                        </TableCell>
                                        <TableCell>
                                            {dateOf(
                                                member.expires_at,
                                                i18n.language,
                                            ) || '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {t(
                                                    `members.statuses.${member.status}`,
                                                    {
                                                        defaultValue:
                                                            member.status,
                                                    },
                                                )}
                                            </Badge>
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-end">
                                                {member.status !==
                                                    'revoked' && (
                                                    <RowActions
                                                        label={member.user.name}
                                                    >
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                setEditing(
                                                                    member,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'members.editAction',
                                                            )}
                                                        </DropdownMenuItem>
                                                        {member.user.id !==
                                                            currentUserId && (
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                onClick={() =>
                                                                    setRevokingMember(
                                                                        member,
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'members.revokeAction',
                                                                )}
                                                            </DropdownMenuItem>
                                                        )}
                                                    </RowActions>
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold tracking-tight">
                    {t('members.invitations.title')}
                </h2>

                {invitations.isError ? (
                    <FormAlert>
                        {genericErrorMessage(invitations.error, t)}
                    </FormAlert>
                ) : invitations.data === undefined ? (
                    <Skeleton className="h-24 w-full" />
                ) : invitations.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('members.invitations.empty')}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('members.form.email')}
                                    </TableHead>
                                    <TableHead>{t('members.roles')}</TableHead>
                                    <TableHead>
                                        {t('members.invitations.validUntil')}
                                    </TableHead>
                                    <TableHead>{t('members.status')}</TableHead>
                                    {canManage && (
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                {t('members.actions')}
                                            </span>
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invitations.data.map((invitation) => (
                                    <TableRow
                                        key={invitation.id}
                                        className={
                                            invitation.status === 'pending'
                                                ? undefined
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <TableCell>
                                            {invitation.email}
                                        </TableCell>
                                        <TableCell>
                                            <span className="flex flex-wrap gap-1">
                                                {invitation.role_ids.map(
                                                    (id) => (
                                                        <Badge
                                                            key={id}
                                                            variant="secondary"
                                                        >
                                                            {roleName(id)}
                                                        </Badge>
                                                    ),
                                                )}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {dateOf(
                                                invitation.expires_at,
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    invitation.status ===
                                                    'pending'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {t(
                                                    `members.invitations.statuses.${invitation.status}`,
                                                    {
                                                        defaultValue:
                                                            invitation.status,
                                                    },
                                                )}
                                            </Badge>
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-end">
                                                {invitation.status ===
                                                    'pending' && (
                                                    <RowActions
                                                        label={invitation.email}
                                                    >
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onClick={() =>
                                                                setRevokingInvitation(
                                                                    invitation,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'members.invitations.withdrawAction',
                                                            )}
                                                        </DropdownMenuItem>
                                                    </RowActions>
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </section>

            <MemberDialog
                companyId={companyId}
                canReadDimensions={canReadDimensions}
                open={inviting}
                onOpenChange={setInviting}
            />
            <MemberDialog
                companyId={companyId}
                canReadDimensions={canReadDimensions}
                membership={editing ?? undefined}
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            />
            <ConfirmDialog
                open={revokingMember !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRevokingMember(null);
                    }
                }}
                title={t('confirm.revokeMember.title', {
                    name: askingMember?.user.name ?? '',
                })}
                description={t('confirm.revokeMember.description')}
                confirmLabel={t('confirm.revokeMember.action')}
                pending={revokeMemberAccess.isPending}
                destructive
                onConfirm={() =>
                    revokingMember && revokeMemberAccess.mutate(revokingMember)
                }
            />
            <ConfirmDialog
                open={revokingInvitation !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRevokingInvitation(null);
                    }
                }}
                title={t('confirm.withdrawInvitation.title', {
                    email: askingInvitation?.email ?? '',
                })}
                description={t('confirm.withdrawInvitation.description')}
                confirmLabel={t('confirm.withdrawInvitation.action')}
                pending={withdraw.isPending}
                destructive
                onConfirm={() =>
                    revokingInvitation && withdraw.mutate(revokingInvitation)
                }
            />
        </>
    );
}
