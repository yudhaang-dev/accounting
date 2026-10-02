import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, ShieldIcon } from 'lucide-react';
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
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import {
    rolesQuery,
    setRoleStatus,
    type Role,
} from '@/modules/identity-access/access';
import { RoleDialog } from '@/modules/identity-access/components/role-dialog';

/**
 * Roles of the company (IA-FR-02): data, not code. A role is never deleted, only deactivated, so the
 * history of who could do what stays readable; a deactivated role can no longer be handed out.
 */
export function RolesPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Role | null>(null);
    const [deactivating, setDeactivating] = useState<Role | null>(null);
    const asking = useLastDefined(deactivating);

    useDocumentTitle(t('roles.pageTitle'));

    const roles = useQuery(rolesQuery(companyId));

    const toggle = useMutation({
        mutationFn: (role: Role) =>
            setRoleStatus(
                companyId,
                role.id,
                role.is_active ? 'deactivate' : 'reactivate',
            ),
        onSuccess: async (role) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'roles'],
            });
            toast.success(
                t(role.is_active ? 'roles.reactivated' : 'roles.deactivated', {
                    name: role.name,
                }),
            );
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setDeactivating(null),
    });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('roles.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('roles.description')}
                    </p>
                </div>
                {canManage && (
                    <Button onClick={() => setCreating(true)}>
                        <PlusIcon data-icon="inline-start" />
                        {t('roles.create')}
                    </Button>
                )}
            </div>

            {roles.isError ? (
                <FormAlert>{genericErrorMessage(roles.error, t)}</FormAlert>
            ) : roles.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : roles.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ShieldIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('roles.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('roles.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('roles.name')}</TableHead>
                                <TableHead className="text-end">
                                    {t('roles.permissions')}
                                </TableHead>
                                <TableHead>{t('roles.status')}</TableHead>
                                {canManage && (
                                    <TableHead className="w-12">
                                        <span className="sr-only">
                                            {t('roles.actions')}
                                        </span>
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roles.data.map((role) => (
                                <TableRow
                                    key={role.id}
                                    className={
                                        role.is_active
                                            ? undefined
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <span className="font-medium">
                                                {role.name}
                                            </span>
                                            {role.description && (
                                                <span className="text-xs text-muted-foreground">
                                                    {role.description}
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {role.permissions.length}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                role.is_active
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {t(
                                                role.is_active
                                                    ? 'roles.active'
                                                    : 'roles.inactive',
                                            )}
                                        </Badge>
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-end">
                                            <RowActions label={role.name}>
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        setEditing(role)
                                                    }
                                                >
                                                    {t('roles.editAction')}
                                                </DropdownMenuItem>
                                                {role.is_active ? (
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() =>
                                                            setDeactivating(
                                                                role,
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'roles.deactivateAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                ) : (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            toggle.mutate(role)
                                                        }
                                                    >
                                                        {t(
                                                            'roles.reactivateAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                )}
                                            </RowActions>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <RoleDialog
                companyId={companyId}
                open={creating}
                onOpenChange={setCreating}
            />
            <RoleDialog
                companyId={companyId}
                role={editing ?? undefined}
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            />
            <ConfirmDialog
                open={deactivating !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeactivating(null);
                    }
                }}
                title={t('confirm.deactivateRole.title', {
                    name: asking?.name ?? '',
                })}
                description={t('confirm.deactivateRole.description')}
                confirmLabel={t('confirm.deactivateRole.action')}
                pending={toggle.isPending}
                destructive
                onConfirm={() => deactivating && toggle.mutate(deactivating)}
            />
        </>
    );
}
