import { Link, useParams } from '@tanstack/react-router';
import { FolderXIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { useDocumentTitle } from '@/hooks/use-document-title';

/**
 * Shown for an address under `/w/$companyId` that matches nothing. The company route marks its own refusal
 * (`data.workspace`): a company the user is not a member of, or that does not exist. Without the mark the
 * workspace is fine and the page is what is missing.
 */
export function WorkspaceNotFound({ data }: { data?: unknown }) {
    const { t } = useTranslation();
    const { companyId } = useParams({ strict: false });
    const workspaceRefused =
        typeof data === 'object' && data !== null && 'workspace' in data;

    if (!workspaceRefused) {
        return <PageMissing companyId={companyId} />;
    }

    useDocumentTitle(t('workspace.notFoundTitle'));

    return (
        <Empty className="max-w-xl flex-none border py-10">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <FolderXIcon />
                </EmptyMedia>
                <EmptyTitle>{t('workspace.notFoundTitle')}</EmptyTitle>
                <EmptyDescription>
                    {t('workspace.notFoundDescription')}
                </EmptyDescription>
            </EmptyHeader>
            <EmptyContent>
                <Button variant="outline" render={<Link to="/workspaces" />}>
                    {t('workspace.backToWorkspaces')}
                </Button>
            </EmptyContent>
        </Empty>
    );
}

function PageMissing({ companyId }: { companyId?: string }) {
    const { t } = useTranslation();

    useDocumentTitle(t('notFound.pageTitle'));

    return (
        <Empty className="max-w-xl flex-none border py-10">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <FolderXIcon />
                </EmptyMedia>
                <EmptyTitle>{t('notFound.title')}</EmptyTitle>
                <EmptyDescription>{t('notFound.description')}</EmptyDescription>
            </EmptyHeader>
            <EmptyContent>
                <Button
                    variant="outline"
                    render={
                        companyId ? (
                            <Link to="/w/$companyId" params={{ companyId }} />
                        ) : (
                            <Link to="/workspaces" />
                        )
                    }
                >
                    {t('notFound.recordBack')}
                </Button>
            </EmptyContent>
        </Empty>
    );
}
