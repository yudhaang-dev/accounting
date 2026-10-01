import { useTranslation } from 'react-i18next';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDocumentTitle } from '@/hooks/use-document-title';
import type { Me } from '@/lib/session';

/** Landing screen after sign-in. Workspaces and companies take over this screen in M1. */
export function HomePage({ me }: { me: Me }) {
    const { t } = useTranslation();

    useDocumentTitle(t('app.pageTitle'));

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('app.welcome', { name: me.name })}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('app.welcomeBack')}
                </p>
            </div>

            <Card className="max-w-xl">
                <CardHeader>
                    <CardTitle>{t('app.emptyTitle')}</CardTitle>
                    <CardDescription>
                        {t('app.emptyDescription')}
                    </CardDescription>
                </CardHeader>
            </Card>
        </>
    );
}
