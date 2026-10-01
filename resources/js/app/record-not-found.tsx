import { Link, useParams } from '@tanstack/react-router';
import { FileQuestionIcon } from 'lucide-react';
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

/** A record (entry, journal) the address names does not exist in this company. */
export function RecordNotFound() {
    const { t } = useTranslation();
    const { companyId } = useParams({ strict: false });

    useDocumentTitle(t('notFound.recordTitle'));

    return (
        <Empty className="max-w-xl border">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <FileQuestionIcon />
                </EmptyMedia>
                <EmptyTitle>{t('notFound.recordTitle')}</EmptyTitle>
                <EmptyDescription>
                    {t('notFound.recordDescription')}
                </EmptyDescription>
            </EmptyHeader>
            {companyId && (
                <EmptyContent>
                    <Button
                        variant="outline"
                        render={
                            <Link to="/w/$companyId" params={{ companyId }} />
                        }
                    >
                        {t('notFound.recordBack')}
                    </Button>
                </EmptyContent>
            )}
        </Empty>
    );
}
