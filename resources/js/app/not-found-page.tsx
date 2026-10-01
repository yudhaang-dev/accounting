import { Link } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';
import { AuthLayout } from '@/components/auth-layout';
import { Button } from '@/components/ui/button';
import { useDocumentTitle } from '@/hooks/use-document-title';

export function NotFoundPage() {
    const { t } = useTranslation();

    useDocumentTitle(t('notFound.pageTitle'));

    return (
        <AuthLayout
            title={t('notFound.title')}
            description={t('notFound.description')}
        >
            <Button render={<Link to="/" />} className="w-full">
                {t('notFound.home')}
            </Button>
        </AuthLayout>
    );
}
