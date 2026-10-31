import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { DownloadIcon, PlusIcon, TagsIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
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
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    PartyRoleDialog,
    PartyRoleImportDialog,
} from '@/modules/subledger/components/party-role-dialogs';
import {
    partyRolesQuery,
    updatePartyRole,
    type PartyRole,
} from '@/modules/subledger/party-roles';

/**
 * Party roles of the company (SL-FR-01): what a party is to the company, such as customer, supplier,
 * employee, or lender. A role says nothing about the side of the ledger; the posting profile does.
 * A role is never deleted, only deactivated.
 */
export function PartyRolesPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [creating, setCreating] = useState(false);
    const [importing, setImporting] = useState(false);
    const [editing, setEditing] = useState<PartyRole | null>(null);

    useDocumentTitle(t('partyRoles.pageTitle'));

    const roles = useQuery(partyRolesQuery(companyId));

    const toggle = useMutation({
        mutationFn: (role: PartyRole) =>
            updatePartyRole(companyId, role.id, {
                lock_version: role.lock_version,
                status: role.status === 'active' ? 'inactive' : 'active',
            }),
        onSuccess: async (role) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'party-roles'],
            });
            toast.success(
                t(
                    role.status === 'active'
                        ? 'partyRoles.reactivated'
                        : 'partyRoles.deactivated',
                    { name: role.label },
                ),
            );
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await queryClient.invalidateQueries({
                    queryKey: ['companies', companyId, 'party-roles'],
                });
                toast.error(t('partyRoles.conflict'));

                return;
            }

            toast.error(actionErrorMessage(error, t));
        },
    });

    const addButton = (
        <Button onClick={() => setCreating(true)}>
            <PlusIcon data-icon="inline-start" />
            {t('partyRoles.add')}
        </Button>
    );
    const importButton = (
        <Button variant="outline" onClick={() => setImporting(true)}>
            <DownloadIcon data-icon="inline-start" />
            {t('partyRoles.importAction')}
        </Button>
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('partyRoles.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('partyRoles.description')}
                    </p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        {importButton}
                        {addButton}
                    </div>
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
                            <TagsIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('partyRoles.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('partyRoles.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && (
                        <EmptyContent>
                            <div className="flex flex-wrap justify-center gap-2">
                                {importButton}
                                {addButton}
                            </div>
                        </EmptyContent>
                    )}
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('partyRoles.label')}</TableHead>
                                <TableHead>{t('partyRoles.key')}</TableHead>
                                <TableHead>{t('partyRoles.status')}</TableHead>
                                {canManage && (
                                    <TableHead className="text-end">
                                        {t('partyRoles.actions')}
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roles.data.map((role) => (
                                <TableRow
                                    key={role.id}
                                    className={
                                        role.status === 'active'
                                            ? undefined
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <TableCell className="font-medium">
                                        {role.label}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {role.key}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                role.status === 'active'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {t(
                                                role.status === 'active'
                                                    ? 'partyRoles.active'
                                                    : 'partyRoles.inactive',
                                            )}
                                        </Badge>
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-end">
                                            <RowActions label={role.label}>
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        setEditing(role)
                                                    }
                                                >
                                                    {t('partyRoles.editAction')}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    disabled={toggle.isPending}
                                                    onClick={() =>
                                                        toggle.mutate(role)
                                                    }
                                                >
                                                    {t(
                                                        role.status === 'active'
                                                            ? 'partyRoles.deactivateAction'
                                                            : 'partyRoles.reactivateAction',
                                                    )}
                                                </DropdownMenuItem>
                                            </RowActions>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <PartyRoleDialog
                companyId={companyId}
                open={creating}
                onOpenChange={setCreating}
            />
            <PartyRoleDialog
                companyId={companyId}
                role={editing ?? undefined}
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            />
            <PartyRoleImportDialog
                companyId={companyId}
                existingKeys={(roles.data ?? []).map((role) => role.key)}
                open={importing}
                onOpenChange={setImporting}
            />
        </>
    );
}
