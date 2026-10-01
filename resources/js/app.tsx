import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { toast } from 'sonner';
import { Toaster } from '@/components/ui/sonner';
import { initI18n } from '@/i18n';
import { setUnauthenticatedHandler } from '@/lib/http';
import { clearMe } from '@/lib/session';
import { createAppRouter } from '@/router';

const i18n = initI18n();

const queryClient = new QueryClient({
    defaultOptions: {
        queries: { retry: 1, refetchOnWindowFocus: false },
        mutations: { retry: false },
    },
});

const router = createAppRouter(queryClient);

// A 401 on a signed-in screen means the session ended (timeout, signed out elsewhere): say so once,
// and return to the login screen with a way back to where the user was.
setUnauthenticatedHandler(() => {
    const { pathname, href } = router.state.location;

    clearMe(queryClient);

    if (
        ['/login', '/two-factor-challenge', '/forgot-password'].includes(
            pathname,
        )
    ) {
        return;
    }

    toast.error(i18n.t('session.expired'), { id: 'session-expired' });
    void router.navigate({ to: '/login', search: { redirect: href } });
});

const root = document.getElementById('root');

if (root) {
    createRoot(root).render(
        <StrictMode>
            <I18nextProvider i18n={i18n}>
                <QueryClientProvider client={queryClient}>
                    <RouterProvider router={router} />
                    <Toaster />
                </QueryClientProvider>
            </I18nextProvider>
        </StrictMode>,
    );
}
