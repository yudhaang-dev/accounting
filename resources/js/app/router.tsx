import type { QueryClient } from '@tanstack/react-query';
import {
    createRootRouteWithContext,
    createRoute,
    createRouter,
    notFound,
    useNavigate,
    Outlet,
    redirect,
    type RouterHistory,
} from '@tanstack/react-router';
import { AppShell } from '@/app/app-shell';
import { RecordNotFound } from '@/app/record-not-found';
import { isApiError } from '@/lib/http';
import { CompanySetupPage } from '@/app/company-setup-page';
import { NewWorkspacePage } from '@/app/new-workspace-page';
import {
    meQuery,
    safeRedirect,
    type Me,
} from '@/modules/identity-access/session';
import { companyQuery } from '@/modules/master-data/company';
import {
    readLastCompany,
    rememberLastCompany,
    workspacesQuery,
    type Workspace,
} from '@/modules/identity-access/workspaces';
import { journalQuery } from '@/modules/general-ledger/journals';
import { JournalPage } from '@/modules/general-ledger/pages/journal-page';
import {
    JournalsPage,
    type JournalSearch,
} from '@/modules/general-ledger/pages/journals-page';
import { TrialBalancePage } from '@/modules/reporting/pages/trial-balance-page';
import { AccountsPage } from '@/modules/general-ledger/pages/accounts-page';
import { PeriodsPage } from '@/modules/general-ledger/pages/periods-page';
import { can } from '@/modules/identity-access/permissions';
import { entryQuery } from '@/modules/transaction-entry/entries';
import { EntriesPage } from '@/modules/transaction-entry/pages/entries-page';
import { EntryFormPage } from '@/modules/transaction-entry/pages/entry-form-page';
import { EntryPage } from '@/modules/transaction-entry/pages/entry-page';
import { CompanyHomePage } from '@/modules/master-data/pages/company-home-page';
import { ForgotPasswordPage } from '@/modules/identity-access/pages/forgot-password-page';
import { LoginPage } from '@/modules/identity-access/pages/login-page';
import { NotFoundPage } from '@/app/not-found-page';
import { ResetPasswordPage } from '@/modules/identity-access/pages/reset-password-page';
import { ErrorScreen, PendingScreen } from '@/app/status-pages';
import { TwoFactorPage } from '@/modules/identity-access/pages/two-factor-page';
import { VerifyEmailPage } from '@/modules/identity-access/pages/verify-email-page';
import { WorkspaceNotFound } from '@/modules/identity-access/pages/workspace-not-found';
import { WorkspacesPage } from '@/modules/identity-access/pages/workspaces-page';

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
    beforeLoad: async ({ context }): Promise<{ workspaces: Workspace[] }> => {
        if (!context.me.email_verified) {
            throw redirect({ to: '/email/verify' });
        }

        return {
            workspaces:
                await context.queryClient.ensureQueryData(workspacesQuery),
        };
    },
    component: function Verified() {
        const { me, workspaces } = verifiedRoute.useRouteContext();

        return (
            <AppShell me={me} workspaces={workspaces}>
                <Outlet />
            </AppShell>
        );
    },
});

// `/` only decides where to go: the company last worked in, the only one there is, the picker, or the
// form of the first workspace.
const indexRoute = createRoute({
    getParentRoute: () => verifiedRoute,
    path: '/',
    beforeLoad: ({ context }) => {
        const { me, workspaces } = context;
        const last = readLastCompany(me.id);
        const target =
            workspaces.find((w) => w.company_id === last) ??
            (workspaces.length === 1 ? workspaces[0] : undefined);

        if (target) {
            throw redirect({
                to: '/w/$companyId',
                params: { companyId: target.company_id },
            });
        }

        // Someone with no workspace has only one thing to do, and it is the first step of the setup:
        // start it, rather than showing an empty list with a button.
        throw workspaces.length === 0
            ? redirect({ to: '/workspaces/new' })
            : redirect({ to: '/workspaces' });
    },
});

const workspacesRoute = createRoute({
    getParentRoute: () => verifiedRoute,
    path: '/workspaces',
    component: function Workspaces() {
        const { me, workspaces } = workspacesRoute.useRouteContext();

        return <WorkspacesPage me={me} workspaces={workspaces} />;
    },
});

// Everything about one company lives under its address, so links, reloads and bookmarks keep the
// workspace; an id the user has no membership for is "not found", never an empty screen.
const companyRoute = createRoute({
    getParentRoute: () => verifiedRoute,
    path: '/w/$companyId',
    beforeLoad: ({ context, params }): { workspace: Workspace } => {
        const workspace = context.workspaces.find(
            (w) => w.company_id === params.companyId,
        );

        if (!workspace) {
            throw notFound();
        }

        rememberLastCompany(context.me.id, params.companyId);

        return { workspace };
    },
    loader: ({ context, params }) =>
        context.queryClient.ensureQueryData(companyQuery(params.companyId)),
    notFoundComponent: WorkspaceNotFound,
    component: Outlet,
});

// A record that does not exist (404) is "not found", not a failure of the screen.
const orNotFound = async <T,>(load: Promise<T>): Promise<T> => {
    try {
        return await load;
    } catch (error) {
        if (isApiError(error) && error.status === 404) {
            throw notFound();
        }

        throw error;
    }
};

// A screen the user may not open sends them to the company home. The API enforces the permission
// regardless; this only avoids showing a screen that would fail on every request.
const requirePermission =
    (permission: string) =>
    ({ context }: { context: { workspace: Workspace } }) => {
        if (!can(context.workspace, permission)) {
            throw redirect({
                to: '/w/$companyId',
                params: { companyId: context.workspace.company_id },
            });
        }
    };

const accountsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/accounts',
    beforeLoad: requirePermission('accounts.read'),
    component: function Accounts() {
        const { workspace } = accountsRoute.useRouteContext();

        return (
            <AccountsPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'accounts.manage')}
            />
        );
    },
});

const periodsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/periods',
    beforeLoad: requirePermission('periods.read'),
    component: function Periods() {
        const { workspace } = periodsRoute.useRouteContext();

        return (
            <PeriodsPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'periods.manage')}
                canClose={can(workspace, 'periods.close')}
                canReopen={can(workspace, 'periods.reopen')}
            />
        );
    },
});

const entriesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/entries',
    beforeLoad: requirePermission('entries.read'),
    component: function Entries() {
        const { workspace } = entriesRoute.useRouteContext();

        return (
            <EntriesPage
                companyId={workspace.company_id}
                canCreate={can(workspace, 'entries.create')}
            />
        );
    },
});

const newEntryRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/entries/new',
    beforeLoad: requirePermission('entries.create'),
    component: function NewEntry() {
        const { workspace } = newEntryRoute.useRouteContext();

        return (
            <EntryFormPage
                companyId={workspace.company_id}
                canManageTypes={can(workspace, 'policies.manage')}
            />
        );
    },
});

const entryRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/entries/$entryId',
    beforeLoad: requirePermission('entries.read'),
    loader: ({ context, params }) =>
        orNotFound(
            context.queryClient.ensureQueryData(
                entryQuery(params.companyId, params.entryId),
            ),
        ),
    notFoundComponent: RecordNotFound,
    component: function Entry() {
        const { workspace, me } = entryRoute.useRouteContext();
        const { entryId } = entryRoute.useParams();

        return (
            <EntryPage
                companyId={workspace.company_id}
                entryId={entryId}
                currentUserId={me.id}
                can={{
                    create: can(workspace, 'entries.create'),
                    submit: can(workspace, 'entries.submit'),
                    approve: can(workspace, 'entries.approve'),
                    reject: can(workspace, 'entries.reject'),
                    post: can(workspace, 'entries.post'),
                    journals: can(workspace, 'journals.read'),
                }}
            />
        );
    },
});

const editEntryRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/entries/$entryId/edit',
    beforeLoad: requirePermission('entries.create'),
    loader: ({ context, params }) =>
        orNotFound(
            context.queryClient.ensureQueryData(
                entryQuery(params.companyId, params.entryId),
            ),
        ),
    notFoundComponent: RecordNotFound,
    component: function EditEntry() {
        const { workspace } = editEntryRoute.useRouteContext();
        const { entryId } = editEntryRoute.useParams();

        return (
            <EntryFormPage
                companyId={workspace.company_id}
                entryId={entryId}
                canManageTypes={can(workspace, 'policies.manage')}
            />
        );
    },
});

const stringSearch = (value: unknown): string | undefined =>
    typeof value === 'string' && value !== '' ? value : undefined;

// The list is the drill-down of reports, so what it filters on lives in the address.
const journalsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/journals',
    beforeLoad: requirePermission('journals.read'),
    validateSearch: (search: Record<string, unknown>): JournalSearch => ({
        account_id: stringSearch(search.account_id),
        from: stringSearch(search.from),
        to: stringSearch(search.to),
    }),
    component: function Journals() {
        const { workspace } = journalsRoute.useRouteContext();
        const navigate = useNavigate();

        return (
            <JournalsPage
                companyId={workspace.company_id}
                search={journalsRoute.useSearch()}
                canReadAccounts={can(workspace, 'accounts.read')}
                onSearchChange={(search) =>
                    void navigate({
                        to: '/w/$companyId/journals',
                        params: { companyId: workspace.company_id },
                        search,
                        replace: true,
                    })
                }
            />
        );
    },
});

const trialBalanceRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/reports/trial-balance',
    beforeLoad: requirePermission('reports.read'),
    component: function TrialBalanceScreen() {
        const { workspace } = trialBalanceRoute.useRouteContext();

        return <TrialBalancePage companyId={workspace.company_id} />;
    },
});

const journalRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/journals/$journalId',
    beforeLoad: requirePermission('journals.read'),
    loader: ({ context, params }) =>
        orNotFound(
            context.queryClient.ensureQueryData(
                journalQuery(params.companyId, params.journalId),
            ),
        ),
    notFoundComponent: RecordNotFound,
    component: function Journal() {
        const { workspace, me } = journalRoute.useRouteContext();
        const { journalId } = journalRoute.useParams();

        return (
            <JournalPage
                companyId={workspace.company_id}
                journalId={journalId}
                currentUserId={me.id}
                access={{
                    entries: can(workspace, 'entries.read'),
                    periods: can(workspace, 'periods.read'),
                    accounts: can(workspace, 'accounts.read'),
                }}
            />
        );
    },
});

const newWorkspaceRoute = createRoute({
    getParentRoute: () => verifiedRoute,
    path: '/workspaces/new',
    component: NewWorkspacePage,
});

// The wizard only exists while the company is in `setup`; afterwards its address leads home.
const companySetupRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/setup',
    loader: ({ context, params }) => {
        const company = context.queryClient.getQueryData(
            companyQuery(params.companyId).queryKey,
        );

        if (company && company.status !== 'setup') {
            throw redirect({
                to: '/w/$companyId',
                params: { companyId: params.companyId },
            });
        }
    },
    component: function CompanySetup() {
        return (
            <CompanySetupPage
                companyId={companySetupRoute.useParams().companyId}
            />
        );
    },
});

// A company still in `setup` opens on its wizard, for whoever may finish it; anyone else would only
// find a screen they cannot use, so they get the home with its note about the setup.
const companyHomeRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/',
    beforeLoad: async ({ context, params }) => {
        const company = await context.queryClient.ensureQueryData(
            companyQuery(params.companyId),
        );

        if (
            company.status === 'setup' &&
            can(context.workspace, 'company.activate')
        ) {
            throw redirect({
                to: '/w/$companyId/setup',
                params: { companyId: params.companyId },
            });
        }
    },
    component: function CompanyHome() {
        return (
            <CompanyHomePage
                companyId={companyHomeRoute.useParams().companyId}
            />
        );
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
        verifiedRoute.addChildren([
            indexRoute,
            workspacesRoute,
            newWorkspaceRoute,
            companyRoute.addChildren([
                companyHomeRoute,
                companySetupRoute,
                accountsRoute,
                periodsRoute,
                entriesRoute,
                newEntryRoute,
                entryRoute,
                editEntryRoute,
                journalsRoute,
                journalRoute,
                trialBalanceRoute,
            ]),
        ]),
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
