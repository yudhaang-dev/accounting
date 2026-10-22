import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { PlusIcon, UsersRoundIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
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
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { CreateGroupDialog } from '@/modules/consolidation/components/group-dialog';
import { groupsQuery } from '@/modules/consolidation/groups';
import { can } from '@/modules/identity-access/permissions';
import type { Workspace } from '@/modules/identity-access/workspaces';

/**
 * The consolidation groups the user holds a grant on (IA-FR-07). Belonging to several companies does not
 * list a group; a group appears only when someone granted access to it. A new group needs the consent of
 * its parent company, which is the permission `consolidation.join` there.
 */
export function GroupsPage({ workspaces }: { workspaces: Workspace[] }) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [creating, setCreating] = useState(false);
    const groups = useQuery(groupsQuery);
    const joinable = workspaces.filter((workspace) =>
        can(workspace, 'consolidation.join'),
    );
    const companyName = new Map(
        workspaces.map((workspace) => [
            workspace.company_id,
            workspace.company_name,
        ]),
    );

    useDocumentTitle(t('consolidation.pageTitle'));

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('consolidation.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('consolidation.description')}
                    </p>
                </div>
                {joinable.length > 0 && (
                    <Button onClick={() => setCreating(true)}>
                        <PlusIcon data-icon="inline-start" />
                        {t('consolidation.newGroup')}
                    </Button>
                )}
            </div>

            {groups.isError ? (
                <FormAlert>{genericErrorMessage(groups.error, t)}</FormAlert>
            ) : groups.data === undefined ? (
                <Skeleton className="h-32 w-full" />
            ) : groups.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <UsersRoundIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('consolidation.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {joinable.length > 0
                                ? t('consolidation.emptyDescription')
                                : t('consolidation.cannotCreate')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('consolidation.group.name')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.group.parentCompany')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.group.currency')}
                                </TableHead>
                                <TableHead>
                                    {t('consolidation.access')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {groups.data.map((group) => (
                                <TableRow key={group.id}>
                                    <TableCell className="font-medium">
                                        <Link
                                            to="/groups/$groupId/perimeter"
                                            params={{ groupId: group.id }}
                                            className="underline underline-offset-2"
                                        >
                                            {group.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {companyName.get(
                                            group.parent_company_id,
                                        ) ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {group.presentation_currency}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {group.abilities.map((ability) => (
                                                <Badge
                                                    key={ability}
                                                    variant="secondary"
                                                >
                                                    {t(
                                                        `consolidation.ability.${ability as 'read' | 'run' | 'approve'}`,
                                                    )}
                                                </Badge>
                                            ))}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <CreateGroupDialog
                open={creating}
                onOpenChange={setCreating}
                companies={joinable}
                onCreated={(group) =>
                    void navigate({
                        to: '/groups/$groupId/perimeter',
                        params: { groupId: group.id },
                    })
                }
            />
        </>
    );
}
