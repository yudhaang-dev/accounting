import { Link } from '@tanstack/react-router';
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

/** The address names a company the user is not a member of (or that does not exist). */
export function WorkspaceNotFound() {
    const { t } = useTranslation();

    useDocumentTitle(t('workspace.notFoundTitle'));

    return (
        <Empty className="max-w-xl border">
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
