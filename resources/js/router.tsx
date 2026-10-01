import type { QueryClient } from '@tanstack/react-query';
import {
    createRootRouteWithContext,
    createRoute,
    createRouter,
    Outlet,
    redirect,
    type RouterHistory,
} from '@tanstack/react-router';
import { AppShell } from '@/components/app/app-shell';
import { meQuery, safeRedirect, type Me } from '@/lib/session';
import { ForgotPasswordPage } from '@/pages/forgot-password-page';
import { HomePage } from '@/pages/home-page';
import { LoginPage } from '@/pages/login-page';
import { NotFoundPage } from '@/pages/not-found-page';
import { ResetPasswordPage } from '@/pages/reset-password-page';
import { ErrorScreen, PendingScreen } from '@/pages/status-pages';
import { TwoFactorPage } from '@/pages/two-factor-page';
import { VerifyEmailPage } from '@/pages/verify-email-page';

type RouterContext = { queryClient: QueryClient };

const rootRoute = createRootRouteWithContext<RouterContext>()({
    component: Outlet,
});

const redirectSearch = (
    search: Record<string, unknown>,
): { redirect?: string } => {
    const redirectTo = safeRedirect(search.redirect);

    return redirectTo ? { redirect: redirectTo } : {};
};

const emailSearch = (search: Record<string, unknown>): { email?: string } =>
    typeof search.email === 'string' && search.email !== ''
        ? { email: search.email }
        : {};

// Signed-out screens. A visitor who already has a session never sees them: they are sent on to
// where they were going, decided before anything renders (no flash of the login form).
const guestRoute = createRoute({
    getParentRoute: () => rootRoute,
    id: 'guest',
    beforeLoad: async ({ context, search }) => {
        const me = await context.queryClient.ensureQueryData(meQuery);

        if (me) {
            const target = safeRedirect(
                (search as Record<string, unknown>).redirect,
            );

            throw target ? redirect({ href: target }) : redirect({ to: '/' });
        }
    },
    component: Outlet,
});

const loginRoute = createRoute({
    getParentRoute: () => guestRoute,
    path: '/login',
    validateSearch: redirectSearch,
    component: function Login() {
        return <LoginPage redirect={loginRoute.useSearch().redirect} />;
    },
});

const twoFactorRoute = createRoute({
    getParentRoute: () => guestRoute,
    path: '/two-factor-challenge',
    validateSearch: redirectSearch,
    component: function TwoFactor() {
        return <TwoFactorPage redirect={twoFactorRoute.useSearch().redirect} />;
    },
});

const forgotPasswordRoute = createRoute({
    getParentRoute: () => guestRoute,
    path: '/forgot-password',
    validateSearch: emailSearch,
    component: function Forgot() {
        return (
            <ForgotPasswordPage email={forgotPasswordRoute.useSearch().email} />
        );
    },
});

const resetPasswordRoute = createRoute({
    getParentRoute: () => guestRoute,
    path: '/reset-password/$token',
    validateSearch: emailSearch,
    component: function Reset() {
        const { token } = resetPasswordRoute.useParams();

        return (
            <ResetPasswordPage
                token={token}
                email={resetPasswordRoute.useSearch().email}
            />
        );
    },
});

// Signed-in screens. The session is checked before rendering; with none, the visitor goes to the
// login screen and comes back to this exact address afterwards.
const authedRoute = createRoute({
    getParentRoute: () => rootRoute,
    id: 'authed',
    beforeLoad: async ({ context, location }): Promise<{ me: Me }> => {
        const me = await context.queryClient.ensureQueryData(meQuery);

        if (!me) {
            throw redirect({
                to: '/login',
                search: { redirect: location.href },
            });
        }

        return { me };
    },
    component: Outlet,
});

const verifyEmailRoute = createRoute({
    getParentRoute: () => authedRoute,
    path: '/email/verify',
    beforeLoad: ({ context }) => {
        if (context.me.email_verified) {
            throw redirect({ to: '/' });
        }
    },
    component: function VerifyEmail() {
        return <VerifyEmailPage me={verifyEmailRoute.useRouteContext().me} />;
    },
});

const verifiedRoute = createRoute({
    getParentRoute: () => authedRoute,
    id: 'verified',
    beforeLoad: ({ context }) => {
        if (!context.me.email_verified) {
            throw redirect({ to: '/email/verify' });
        }
    },
    component: function Verified() {
        return (
            <AppShell me={verifiedRoute.useRouteContext().me}>
                <Outlet />
            </AppShell>
        );
    },
});

const homeRoute = createRoute({
    getParentRoute: () => verifiedRoute,
    path: '/',
    component: function Home() {
        return <HomePage me={homeRoute.useRouteContext().me} />;
    },
});

const routeTree = rootRoute.addChildren([
    guestRoute.addChildren([
        loginRoute,
        twoFactorRoute,
        forgotPasswordRoute,
        resetPasswordRoute,
    ]),
    authedRoute.addChildren([
        verifyEmailRoute,
        verifiedRoute.addChildren([homeRoute]),
    ]),
]);

export function createAppRouter(
    queryClient: QueryClient,
    history?: RouterHistory,
) {
    return createRouter({
        routeTree,
        history,
        context: { queryClient },
        defaultPreload: 'intent',
        defaultPendingMs: 200,
        defaultPendingMinMs: 300,
        defaultPendingComponent: PendingScreen,
        defaultErrorComponent: ErrorScreen,
        defaultNotFoundComponent: NotFoundPage,
        scrollRestoration: true,
    });
}

export type AppRouter = ReturnType<typeof createAppRouter>;

declare module '@tanstack/react-router' {
    interface Register {
        router: AppRouter;
    }
}
