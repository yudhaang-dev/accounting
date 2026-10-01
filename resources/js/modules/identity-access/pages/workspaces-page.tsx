import { Link } from '@tanstack/react-router';
import {
    BuildingIcon,
    ChevronRightIcon,
    FolderOpenIcon,
    PlusIcon,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { useDocumentTitle } from '@/hooks/use-document-title';
import type { Me } from '@/modules/identity-access/session';
import type { Workspace } from '@/modules/identity-access/workspaces';

/** Where a user with several workspaces (or none yet) chooses one to work in. */
export function WorkspacesPage({
    me,
    workspaces,
}: {
    me: Me;
    workspaces: Workspace[];
}) {
    const { t } = useTranslation();

    useDocumentTitle(t('workspace.pageTitle'));

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('app.welcome', { name: me.name })}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('workspace.description')}
                    </p>
                </div>
                {workspaces.length > 0 && (
                    <Button
                        variant="outline"
                        render={<Link to="/workspaces/new" />}
                    >
                        <PlusIcon data-icon="inline-start" />
                        {t('workspace.create')}
                    </Button>
                )}
            </div>

            {workspaces.length === 0 ? (
                <Empty className="max-w-xl border">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <FolderOpenIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('workspace.emptyTitle')}</EmptyTitle>
                        <EmptyDescription>
                            {t('workspace.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>
                        <Button render={<Link to="/workspaces/new" />}>
                            <PlusIcon data-icon="inline-start" />
                            {t('workspace.create')}
                        </Button>
                    </EmptyContent>
                </Empty>
            ) : (
                <ul className="grid max-w-3xl gap-3 sm:grid-cols-2">
                    {workspaces.map((workspace) => (
                        <li key={workspace.id}>
                            <Link
                                to="/w/$companyId"
                                params={{ companyId: workspace.company_id }}
                                aria-label={t('workspace.open', {
                                    name: workspace.name,
                                })}
                                className="group block rounded-xl outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                <Card
                                    size="sm"
                                    className="transition-colors group-hover:bg-muted/50"
                                >
                                    <CardContent className="flex items-center gap-3">
                                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                            <BuildingIcon
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </div>
                                        <div className="grid min-w-0 flex-1 leading-tight">
                                            <span className="truncate font-medium">
                                                {workspace.name}
                                            </span>
                                            <span className="truncate text-sm text-muted-foreground">
                                                {t('workspace.company', {
                                                    name: workspace.company_name,
                                                })}
                                            </span>
                                        </div>
                                        <ChevronRightIcon
                                            className="size-4 text-muted-foreground"
                                            aria-hidden
                                        />
                                    </CardContent>
                                </Card>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
