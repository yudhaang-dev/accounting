import { useRouter, type ErrorComponentProps } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';
import { AuthLayout } from '@/components/auth-layout';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { genericErrorMessage } from '@/lib/errors';

/** Shown while a route is still loading for longer than a blink (the router delays it briefly). */
export function PendingScreen() {
    const { t } = useTranslation();

    return (
        <div
            role="status"
            className="flex min-h-svh items-center justify-center text-muted-foreground"
        >
            <Spinner className="size-6" />
            <span className="sr-only">{t('common.loading')}</span>
        </div>
    );
}

/** A route failed to load (typically no connection while checking the session): offer a retry. */
export function ErrorScreen({ error, reset }: ErrorComponentProps) {
    const { t } = useTranslation();
    const router = useRouter();

    return (
        <AuthLayout
            title={t('common.unexpected')}
            description={genericErrorMessage(error, t)}
        >
            <Button
                className="w-full"
                onClick={() => {
                    reset();
                    void router.invalidate();
                }}
            >
                {t('common.retry')}
            </Button>
        </AuthLayout>
    );
}
