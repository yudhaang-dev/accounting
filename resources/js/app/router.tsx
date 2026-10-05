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
import type { Crumb, CrumbMatch } from '@/app/breadcrumbs';
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
import { LedgerSettingsPage } from '@/modules/general-ledger/pages/ledger-settings-page';
import { OpeningBalancesPage } from '@/modules/general-ledger/pages/opening-balances-page';
import { PeriodsPage } from '@/modules/general-ledger/pages/periods-page';
import { can } from '@/modules/identity-access/permissions';
import { entryQuery } from '@/modules/transaction-entry/entries';
import { ReverseJournalButton } from '@/modules/transaction-entry/components/reverse-journal-button';
import { RecurringSchedulesPage } from '@/modules/transaction-entry/pages/recurring-schedules-page';
import { ScheduleFormPage } from '@/modules/transaction-entry/pages/schedule-form-page';
import { scheduleQuery } from '@/modules/transaction-entry/recurring-schedules';
import { ApprovalPolicyPage } from '@/modules/transaction-entry/pages/approval-policy-page';
import { ApprovalsPage } from '@/modules/transaction-entry/pages/approvals-page';
import { EntriesPage } from '@/modules/transaction-entry/pages/entries-page';
import { EntryFormPage } from '@/modules/transaction-entry/pages/entry-form-page';
import { EntryPage } from '@/modules/transaction-entry/pages/entry-page';
import { transactionTypeQuery } from '@/modules/transaction-entry/transaction-types';
import { TransactionTypePage } from '@/modules/transaction-entry/pages/transaction-type-page';
import { TransactionTypesPage } from '@/modules/transaction-entry/pages/transaction-types-page';
import { CompanyHomePage } from '@/modules/master-data/pages/company-home-page';
import { CurrenciesPage } from '@/modules/master-data/pages/currencies-page';
import { DimensionsPage } from '@/modules/master-data/pages/dimensions-page';
import { ExchangeRatesPage } from '@/modules/master-data/pages/exchange-rates-page';
import { RateTypesPage } from '@/modules/master-data/pages/rate-types-page';
import { CashAccountsPage } from '@/modules/subledger/pages/cash-accounts-page';
import { CashTransactionsPage } from '@/modules/subledger/pages/cash-transactions-page';
import { AllocationsPage } from '@/modules/subledger/pages/allocations-page';
import { DocumentsPage } from '@/modules/subledger/pages/documents-page';
import { OpenItemsPage } from '@/modules/subledger/pages/open-items-page';
import { PartiesPage } from '@/modules/subledger/pages/parties-page';
import { PostingProfilesPage } from '@/modules/subledger/pages/posting-profiles-page';
import { ForgotPasswordPage } from '@/modules/identity-access/pages/forgot-password-page';
import { LoginPage } from '@/modules/identity-access/pages/login-page';
import { NotFoundPage } from '@/app/not-found-page';
import { ResetPasswordPage } from '@/modules/identity-access/pages/reset-password-page';
import { ErrorScreen, PendingScreen } from '@/app/status-pages';
import { TwoFactorPage } from '@/modules/identity-access/pages/two-factor-page';
import { VerifyEmailPage } from '@/modules/identity-access/pages/verify-email-page';
import { WorkspaceNotFound } from '@/modules/identity-access/pages/workspace-not-found';
import { WorkspacesPage } from '@/modules/identity-access/pages/workspaces-page';
import { AcceptInvitationPage } from '@/modules/identity-access/pages/accept-invitation-page';
import { IntegrationClientsPage } from '@/modules/identity-access/pages/integration-clients-page';
import { MembersPage } from '@/modules/identity-access/pages/members-page';
import { RolesPage } from '@/modules/identity-access/pages/roles-page';
import { SecurityPolicyPage } from '@/modules/identity-access/pages/security-policy-page';

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
    staticData: {
        crumbs: () => [{ labelKey: 'breadcrumb.workspaces' }],
    },
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
    staticData: {
        crumbs: (m) => [{ label: workspaceName(m), href: at(m, '') }],
    },
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
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navAccounts', href: at(m, '/accounts') },
        ],
    },
    beforeLoad: requirePermission('accounts.read'),
    component: function Accounts() {
        const { workspace } = accountsRoute.useRouteContext();

        return (
            <AccountsPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'accounts.manage')}
                access={{
                    currencies: can(workspace, 'currencies.read'),
                    dimensions: can(workspace, 'dimensions.read'),
                }}
            />
        );
    },
});

const periodsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/periods',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navPeriods', href: at(m, '/periods') },
        ],
    },
    beforeLoad: requirePermission('periods.read'),
    component: function Periods() {
        const { workspace } = periodsRoute.useRouteContext();

        return (
            <PeriodsPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'periods.manage')}
                canClose={can(workspace, 'periods.close')}
                canReopen={can(workspace, 'periods.reopen')}
                canRevalue={can(workspace, 'periods.revalue')}
                yearClosing={{
                    close: can(workspace, 'fiscal-years.close'),
                    reopen: can(workspace, 'fiscal-years.reopen'),
                    entries: can(workspace, 'entries.read'),
                }}
                revaluationAccess={{
                    accounts: can(workspace, 'accounts.read'),
                    journals: can(workspace, 'journals.read'),
                    openItems: can(workspace, 'open-items.read'),
                }}
            />
        );
    },
});

const ledgerSettingsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/ledger-settings',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navLedgerSettings',
                href: at(m, '/ledger-settings'),
            },
        ],
    },
    beforeLoad: requirePermission('accounts.read'),
    component: function LedgerSettings() {
        const { workspace } = ledgerSettingsRoute.useRouteContext();

        return (
            <LedgerSettingsPage
                companyId={workspace.company_id}
                canManageRoles={can(workspace, 'accounts.manage')}
                canManagePolicy={can(workspace, 'policies.manage')}
            />
        );
    },
});

const openingBalancesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/opening-balances',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navOpeningBalances',
                href: at(m, '/opening-balances'),
            },
        ],
    },
    beforeLoad: requirePermission('journals.read'),
    component: function OpeningBalances() {
        const { workspace } = openingBalancesRoute.useRouteContext();

        return (
            <OpeningBalancesPage
                companyId={workspace.company_id}
                canImport={can(workspace, 'opening-balances.import')}
                canReadJournals={can(workspace, 'journals.read')}
            />
        );
    },
});

const dimensionsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/dimensions',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navDimensions', href: at(m, '/dimensions') },
        ],
    },
    beforeLoad: requirePermission('dimensions.read'),
    component: function Dimensions() {
        const { workspace } = dimensionsRoute.useRouteContext();

        return (
            <DimensionsPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'dimensions.manage')}
            />
        );
    },
});

const currenciesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/currencies',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navCurrencies', href: at(m, '/currencies') },
        ],
    },
    beforeLoad: requirePermission('currencies.read'),
    component: function Currencies() {
        const { workspace } = currenciesRoute.useRouteContext();

        return (
            <CurrenciesPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'currencies.manage')}
            />
        );
    },
});

const exchangeRatesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/exchange-rates',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navRates', href: at(m, '/exchange-rates') },
        ],
    },
    beforeLoad: requirePermission('exchange-rates.read'),
    component: function ExchangeRates() {
        const { workspace } = exchangeRatesRoute.useRouteContext();

        return (
            <ExchangeRatesPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'exchange-rates.manage')}
                canReadTypes={can(workspace, 'exchange-rates.read')}
            />
        );
    },
});

const rateTypesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/exchange-rates/types',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navRates', href: at(m, '/exchange-rates') },
            { labelKey: 'breadcrumb.rateTypes' },
        ],
    },
    beforeLoad: requirePermission('exchange-rates.read'),
    component: function RateTypes() {
        const { workspace } = rateTypesRoute.useRouteContext();

        return (
            <RateTypesPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'exchange-rates.manage')}
            />
        );
    },
});

const cashAccountsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/cash-accounts',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navCashAccounts', href: at(m, '/cash-accounts') },
        ],
    },
    beforeLoad: requirePermission('cash-accounts.read'),
    component: function CashAccounts() {
        const { workspace } = cashAccountsRoute.useRouteContext();

        return (
            <CashAccountsPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'cash-accounts.manage')}
                canReadAccounts={can(workspace, 'accounts.read')}
            />
        );
    },
});

const cashTransactionsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/cash-transactions',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navCashTransactions',
                href: at(m, '/cash-transactions'),
            },
        ],
    },
    beforeLoad: requirePermission('cash-transactions.read'),
    component: function CashTransactions() {
        const { workspace } = cashTransactionsRoute.useRouteContext();

        return (
            <CashTransactionsPage
                companyId={workspace.company_id}
                canClear={can(workspace, 'cash-transactions.clear')}
                canReadJournals={can(workspace, 'journals.read')}
            />
        );
    },
});

const documentsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/documents',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navDocuments', href: at(m, '/documents') },
        ],
    },
    beforeLoad: requirePermission('documents.read'),
    component: function Documents() {
        const { workspace } = documentsRoute.useRouteContext();

        return (
            <DocumentsPage
                companyId={workspace.company_id}
                access={{
                    parties: can(workspace, 'parties.read'),
                    journals: can(workspace, 'journals.read'),
                    entries: can(workspace, 'entries.read'),
                }}
                reverseAction={
                    can(workspace, 'entries.reverse')
                        ? (document) => (
                              <div>
                                  <ReverseJournalButton
                                      companyId={workspace.company_id}
                                      journalId={document.journal_id}
                                      journalNumber={document.document_number}
                                      subject="document"
                                  />
                              </div>
                          )
                        : undefined
                }
            />
        );
    },
});

const openItemsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/open-items',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navOpenItems', href: at(m, '/open-items') },
        ],
    },
    beforeLoad: requirePermission('open-items.read'),
    component: function OpenItems() {
        const { workspace } = openItemsRoute.useRouteContext();

        return (
            <OpenItemsPage
                companyId={workspace.company_id}
                canReadParties={can(workspace, 'parties.read')}
                canReadAllocations={can(workspace, 'allocations.read')}
            />
        );
    },
});

const allocationsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/allocations',
    validateSearch: (
        search: Record<string, unknown>,
    ): { open_item?: string } =>
        typeof search.open_item === 'string' && search.open_item !== ''
            ? { open_item: search.open_item }
            : {},
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navAllocations', href: at(m, '/allocations') },
        ],
    },
    beforeLoad: requirePermission('allocations.read'),
    component: function Allocations() {
        const { workspace } = allocationsRoute.useRouteContext();
        const { open_item } = allocationsRoute.useSearch();

        return (
            <AllocationsPage
                companyId={workspace.company_id}
                openItemId={open_item}
                canManage={can(workspace, 'allocations.manage')}
                access={{
                    openItems: can(workspace, 'open-items.read'),
                    parties: can(workspace, 'parties.read'),
                    journals: can(workspace, 'journals.read'),
                }}
            />
        );
    },
});

const partiesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/parties',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navParties', href: at(m, '/parties') },
        ],
    },
    beforeLoad: requirePermission('parties.read'),
    component: function Parties() {
        const { workspace } = partiesRoute.useRouteContext();

        return (
            <PartiesPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'parties.manage')}
                canReadProfiles={can(workspace, 'posting-profiles.read')}
            />
        );
    },
});

const postingProfilesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/posting-profiles',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navPostingProfiles',
                href: at(m, '/posting-profiles'),
            },
        ],
    },
    beforeLoad: requirePermission('posting-profiles.read'),
    component: function PostingProfiles() {
        const { workspace } = postingProfilesRoute.useRouteContext();

        return (
            <PostingProfilesPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'posting-profiles.manage')}
                canManageAccounts={can(workspace, 'accounts.manage')}
            />
        );
    },
});

const membersRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/members',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navMembers', href: at(m, '/members') },
        ],
    },
    beforeLoad: requirePermission('memberships.read'),
    component: function Members() {
        const { workspace, me } = membersRoute.useRouteContext();

        return (
            <MembersPage
                companyId={workspace.company_id}
                currentUserId={me.id}
                canManage={can(workspace, 'memberships.manage')}
                canReadRoles={can(workspace, 'roles.read')}
                canReadDimensions={can(workspace, 'dimensions.read')}
            />
        );
    },
});

const rolesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/roles',
    staticData: {
        crumbs: (m) => [{ labelKey: 'app.navRoles', href: at(m, '/roles') }],
    },
    beforeLoad: requirePermission('roles.read'),
    component: function Roles() {
        const { workspace } = rolesRoute.useRouteContext();

        return (
            <RolesPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'roles.manage')}
            />
        );
    },
});

const securityPolicyRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/security-policy',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navSecurityPolicy',
                href: at(m, '/security-policy'),
            },
        ],
    },
    beforeLoad: requirePermission('policies.manage'),
    component: function SecurityPolicy() {
        const { workspace } = securityPolicyRoute.useRouteContext();

        return <SecurityPolicyPage companyId={workspace.company_id} />;
    },
});

const integrationClientsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/integration-clients',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navIntegrations',
                href: at(m, '/integration-clients'),
            },
        ],
    },
    beforeLoad: requirePermission('integration-clients.manage'),
    component: function IntegrationClients() {
        const { workspace } = integrationClientsRoute.useRouteContext();

        return <IntegrationClientsPage companyId={workspace.company_id} />;
    },
});

const entriesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/entries',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navEntries', href: at(m, '/entries') },
        ],
    },
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

const approvalsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/approvals',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navApprovals', href: at(m, '/approvals') },
        ],
    },
    beforeLoad: requirePermission('entries.approve'),
    component: function Approvals() {
        const { workspace, me } = approvalsRoute.useRouteContext();

        return (
            <ApprovalsPage
                companyId={workspace.company_id}
                permissions={workspace.permissions}
                userId={me.id}
            />
        );
    },
});

const approvalPolicyRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/approval-policy',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navApprovalPolicy',
                href: at(m, '/approval-policy'),
            },
        ],
    },
    beforeLoad: requirePermission('policies.manage'),
    component: function ApprovalPolicy() {
        const { workspace } = approvalPolicyRoute.useRouteContext();

        return <ApprovalPolicyPage companyId={workspace.company_id} />;
    },
});

const newEntryRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/entries/new',
    validateSearch: (search: Record<string, unknown>): { replaces?: string } =>
        typeof search.replaces === 'string' && search.replaces !== ''
            ? { replaces: search.replaces }
            : {},
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navEntries', href: at(m, '/entries') },
            { labelKey: 'breadcrumb.new' },
        ],
    },
    beforeLoad: requirePermission('entries.create'),
    component: function NewEntry() {
        const { workspace } = newEntryRoute.useRouteContext();
        const { replaces } = newEntryRoute.useSearch();

        return (
            <EntryFormPage
                companyId={workspace.company_id}
                replacesId={replaces}
                canManageTypes={can(workspace, 'policies.manage')}
                canUseDimensions={can(workspace, 'dimensions.read')}
            />
        );
    },
});

const entryRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/entries/$entryId',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navEntries', href: at(m, '/entries') },
            { label: numberOf(m), href: at(m, `/entries/${m.params.entryId}`) },
        ],
    },
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
                    reverse: can(workspace, 'entries.reverse'),
                    journals: can(workspace, 'journals.read'),
                    dimensions: can(workspace, 'dimensions.read'),
                }}
            />
        );
    },
});

const editEntryRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/entries/$entryId/edit',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navEntries', href: at(m, '/entries') },
            { label: numberOf(m), href: at(m, `/entries/${m.params.entryId}`) },
            { labelKey: 'breadcrumb.edit' },
        ],
    },
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
                canUseDimensions={can(workspace, 'dimensions.read')}
            />
        );
    },
});

const schedulesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/recurring-schedules',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navSchedules',
                href: at(m, '/recurring-schedules'),
            },
        ],
    },
    beforeLoad: requirePermission('recurring-schedules.read'),
    component: function Schedules() {
        const { workspace } = schedulesRoute.useRouteContext();

        return (
            <RecurringSchedulesPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'recurring-schedules.manage')}
            />
        );
    },
});

const newScheduleRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/recurring-schedules/new',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navSchedules',
                href: at(m, '/recurring-schedules'),
            },
            { labelKey: 'breadcrumb.new' },
        ],
    },
    beforeLoad: requirePermission('recurring-schedules.manage'),
    component: function NewSchedule() {
        const { workspace } = newScheduleRoute.useRouteContext();

        return (
            <ScheduleFormPage
                companyId={workspace.company_id}
                canUseDimensions={can(workspace, 'dimensions.read')}
            />
        );
    },
});

const editScheduleRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/recurring-schedules/$scheduleId/edit',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navSchedules',
                href: at(m, '/recurring-schedules'),
            },
            { label: nameOf(m) },
            { labelKey: 'breadcrumb.edit' },
        ],
    },
    beforeLoad: requirePermission('recurring-schedules.manage'),
    loader: ({ context, params }) =>
        orNotFound(
            context.queryClient.ensureQueryData(
                scheduleQuery(params.companyId, params.scheduleId),
            ),
        ),
    notFoundComponent: RecordNotFound,
    component: function EditSchedule() {
        const { workspace } = editScheduleRoute.useRouteContext();
        const { scheduleId } = editScheduleRoute.useParams();

        return (
            <ScheduleFormPage
                companyId={workspace.company_id}
                scheduleId={scheduleId}
                canUseDimensions={can(workspace, 'dimensions.read')}
            />
        );
    },
});

const transactionTypesRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/transaction-types',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navTransactionTypes',
                href: at(m, '/transaction-types'),
            },
        ],
    },
    beforeLoad: requirePermission('entries.read'),
    component: function TransactionTypes() {
        const { workspace } = transactionTypesRoute.useRouteContext();

        return (
            <TransactionTypesPage
                companyId={workspace.company_id}
                canManage={can(workspace, 'policies.manage')}
            />
        );
    },
});

const transactionTypeRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/transaction-types/$typeId',
    staticData: {
        crumbs: (m) => [
            {
                labelKey: 'app.navTransactionTypes',
                href: at(m, '/transaction-types'),
            },
            {
                label: labelOf(m),
                href: at(m, `/transaction-types/${m.params.typeId}`),
            },
        ],
    },
    beforeLoad: requirePermission('entries.read'),
    loader: ({ context, params }) =>
        orNotFound(
            context.queryClient.ensureQueryData(
                transactionTypeQuery(params.companyId, params.typeId),
            ),
        ),
    notFoundComponent: RecordNotFound,
    component: function TransactionType() {
        const { workspace } = transactionTypeRoute.useRouteContext();
        const { typeId } = transactionTypeRoute.useParams();

        return (
            <TransactionTypePage
                companyId={workspace.company_id}
                typeId={typeId}
                canManage={can(workspace, 'policies.manage')}
            />
        );
    },
});

// What a route's `crumbs` function sees: the address, the route context and the loaded record.
const at = (m: CrumbMatch, path: string) => `/w/${m.params.companyId}${path}`;
const workspaceName = (m: CrumbMatch) =>
    (m.context.workspace as Workspace | undefined)?.name ?? '';
// The number of the entry or journal the route loaded, for the last step of the trail.
const numberOf = (m: CrumbMatch) =>
    (m.loaderData as { number?: string | null } | undefined)?.number ?? '…';

// The name of the schedule the route loaded, for the middle step of the trail.
const nameOf = (m: CrumbMatch) =>
    (m.loaderData as { name?: string } | undefined)?.name ?? '…';

// The name of the transaction type the route loaded, for the last step of the trail.
const labelOf = (m: CrumbMatch) =>
    (m.loaderData as { label?: string } | undefined)?.label ?? '…';

const stringSearch = (value: unknown): string | undefined =>
    typeof value === 'string' && value !== '' ? value : undefined;

// The list is the drill-down of reports, so what it filters on lives in the address.
const journalsRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/journals',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navJournals', href: at(m, '/journals') },
        ],
    },
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
    staticData: {
        crumbs: (m) => [
            { labelKey: 'breadcrumb.reports' },
            {
                labelKey: 'app.navTrialBalance',
                href: at(m, '/reports/trial-balance'),
            },
        ],
    },
    beforeLoad: requirePermission('reports.read'),
    component: function TrialBalanceScreen() {
        const { workspace } = trialBalanceRoute.useRouteContext();

        return <TrialBalancePage companyId={workspace.company_id} />;
    },
});

const journalRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/journals/$journalId',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'app.navJournals', href: at(m, '/journals') },
            {
                label: numberOf(m),
                href: at(m, `/journals/${m.params.journalId}`),
            },
        ],
    },
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
        const journal = journalRoute.useLoaderData();

        return (
            <JournalPage
                companyId={workspace.company_id}
                journalId={journalId}
                currentUserId={me.id}
                actions={
                    can(workspace, 'entries.reverse') ? (
                        <ReverseJournalButton
                            companyId={workspace.company_id}
                            journalId={journalId}
                            journalNumber={journal.number}
                        />
                    ) : undefined
                }
                access={{
                    entries: can(workspace, 'entries.read'),
                    periods: can(workspace, 'periods.read'),
                    accounts: can(workspace, 'accounts.read'),
                    dimensions: can(workspace, 'dimensions.read'),
                }}
            />
        );
    },
});

// The link of an invitation e-mail. Outside any company: the invited person does not belong to one yet.
const acceptInvitationRoute = createRoute({
    getParentRoute: () => verifiedRoute,
    path: '/invitations/accept',
    validateSearch: (search: Record<string, unknown>): { token?: string } =>
        typeof search.token === 'string' && search.token !== ''
            ? { token: search.token }
            : {},
    staticData: {
        crumbs: () => [{ labelKey: 'invitation.crumb' }],
    },
    component: function AcceptInvitation() {
        return (
            <AcceptInvitationPage
                token={acceptInvitationRoute.useSearch().token}
            />
        );
    },
});

const newWorkspaceRoute = createRoute({
    getParentRoute: () => verifiedRoute,
    path: '/workspaces/new',
    staticData: {
        crumbs: () => [
            { labelKey: 'breadcrumb.workspaces', href: '/workspaces' },
            { labelKey: 'breadcrumb.new' },
        ],
    },
    component: NewWorkspacePage,
});

// The wizard only exists while the company is in `setup`; afterwards its address leads home.
const companySetupRoute = createRoute({
    getParentRoute: () => companyRoute,
    path: '/setup',
    staticData: {
        crumbs: (m) => [
            { labelKey: 'breadcrumb.setup', href: at(m, '/setup') },
        ],
    },
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
            acceptInvitationRoute,
            companyRoute.addChildren([
                companyHomeRoute,
                companySetupRoute,
                accountsRoute,
                periodsRoute,
                ledgerSettingsRoute,
                openingBalancesRoute,
                dimensionsRoute,
                currenciesRoute,
                exchangeRatesRoute,
                rateTypesRoute,
                partiesRoute,
                cashAccountsRoute,
                cashTransactionsRoute,
                documentsRoute,
                openItemsRoute,
                allocationsRoute,
                postingProfilesRoute,
                entriesRoute,
                approvalsRoute,
                approvalPolicyRoute,
                newEntryRoute,
                entryRoute,
                editEntryRoute,
                schedulesRoute,
                newScheduleRoute,
                editScheduleRoute,
                transactionTypesRoute,
                transactionTypeRoute,
                journalsRoute,
                journalRoute,
                trialBalanceRoute,
                membersRoute,
                rolesRoute,
                securityPolicyRoute,
                integrationClientsRoute,
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

    // The trail a screen shows in the header, declared on its route (see `Breadcrumbs`).
    interface StaticDataRouteOption {
        crumbs?: (match: CrumbMatch) => Crumb[];
    }
}
