import { Link, useMatchRoute, useParams } from '@tanstack/react-router';
import {
    ArrowDownToLineIcon,
    ArrowRightLeftIcon,
    FileSpreadsheetIcon,
    ClipboardListIcon,
    LandmarkIcon,
    BookOpenIcon,
    BookTextIcon,
    CalendarRangeIcon,
    CoinsIcon,
    FileStackIcon,
    FileTextIcon,
    ReceiptTextIcon,
    ScaleIcon,
    BanknoteIcon,
    HourglassIcon,
    LayersIcon,
    ChevronsUpDownIcon,
    HouseIcon,
    InboxIcon,
    ListChecksIcon,
    ListTreeIcon,
    LockKeyholeIcon,
    LogOutIcon,
    NetworkIcon,
    PlugIcon,
    RepeatIcon,
    ShapesIcon,
    ShieldCheckIcon,
    SplitIcon,
    SigmaIcon,
    SlidersHorizontalIcon,
    TrendingUpIcon,
    UsersIcon,
    UsersRoundIcon,
    WorkflowIcon,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { Spinner } from '@/components/ui/spinner';
import { LanguageSwitcher } from '@/components/language-switcher';
import { useSignOut } from '@/modules/identity-access/hooks/use-sign-out';
import { can } from '@/modules/identity-access/permissions';
import type { Me } from '@/modules/identity-access/session';
import type { Workspace } from '@/modules/identity-access/workspaces';
import { Breadcrumbs } from '@/app/breadcrumbs';
import { Separator } from '@/components/ui/separator';
import { WorkspaceSwitcher } from '@/modules/identity-access/components/workspace-switcher';

/** Company screens in the sidebar, grouped by area; an item shows only for users holding its permission, and a group with no visible item is left out. */
const NAV = [
    {
        label: null,
        items: [
            {
                to: '/w/$companyId',
                label: 'app.navHome',
                icon: HouseIcon,
                permission: null,
            },
        ],
    },
    {
        label: 'app.navGroupAccounting',
        items: [
            {
                to: '/w/$companyId/accounts',
                label: 'app.navAccounts',
                icon: ListTreeIcon,
                permission: 'accounts.read',
            },
            {
                to: '/w/$companyId/periods',
                label: 'app.navPeriods',
                icon: CalendarRangeIcon,
                permission: 'periods.read',
            },
            {
                to: '/w/$companyId/ledger-settings',
                label: 'app.navLedgerSettings',
                icon: SlidersHorizontalIcon,
                permission: 'accounts.read',
            },
            {
                to: '/w/$companyId/opening-balances',
                label: 'app.navOpeningBalances',
                icon: ArrowDownToLineIcon,
                permission: 'journals.read',
            },
            {
                to: '/w/$companyId/journals',
                label: 'app.navJournals',
                icon: BookTextIcon,
                permission: 'journals.read',
            },
        ],
    },
    {
        label: 'app.navGroupMasterData',
        items: [
            {
                to: '/w/$companyId/dimensions',
                label: 'app.navDimensions',
                icon: ShapesIcon,
                permission: 'dimensions.read',
            },
            {
                to: '/w/$companyId/currencies',
                label: 'app.navCurrencies',
                icon: CoinsIcon,
                permission: 'currencies.read',
            },
            {
                to: '/w/$companyId/exchange-rates',
                label: 'app.navRates',
                icon: TrendingUpIcon,
                permission: 'exchange-rates.read',
            },
        ],
    },
    {
        label: 'app.navGroupTransactions',
        items: [
            {
                to: '/w/$companyId/entries',
                label: 'app.navEntries',
                icon: FileTextIcon,
                permission: 'entries.read',
            },
            {
                to: '/w/$companyId/approvals',
                label: 'app.navApprovals',
                icon: InboxIcon,
                permission: 'entries.approve',
            },
            {
                to: '/w/$companyId/recurring-schedules',
                label: 'app.navSchedules',
                icon: RepeatIcon,
                permission: 'recurring-schedules.read',
            },
            {
                to: '/w/$companyId/transaction-types',
                label: 'app.navTransactionTypes',
                icon: LayersIcon,
                permission: 'entries.read',
            },
            {
                to: '/w/$companyId/approval-policy',
                label: 'app.navApprovalPolicy',
                icon: ListChecksIcon,
                permission: 'policies.manage',
            },
        ],
    },
    {
        label: 'app.navGroupSubledger',
        items: [
            {
                to: '/w/$companyId/parties',
                label: 'app.navParties',
                icon: UsersRoundIcon,
                permission: 'parties.read',
            },
            {
                to: '/w/$companyId/posting-profiles',
                label: 'app.navPostingProfiles',
                icon: WorkflowIcon,
                permission: 'posting-profiles.read',
            },
            {
                to: '/w/$companyId/cash-accounts',
                label: 'app.navCashAccounts',
                icon: LandmarkIcon,
                permission: 'cash-accounts.read',
            },
            {
                to: '/w/$companyId/cash-transactions',
                label: 'app.navCashTransactions',
                icon: ArrowRightLeftIcon,
                permission: 'cash-transactions.read',
            },
            {
                to: '/w/$companyId/bank-statements',
                label: 'app.navBankStatements',
                icon: FileSpreadsheetIcon,
                permission: 'bank-statements.read',
            },
            {
                to: '/w/$companyId/documents',
                label: 'app.navDocuments',
                icon: FileStackIcon,
                permission: 'documents.read',
            },
            {
                to: '/w/$companyId/open-items',
                label: 'app.navOpenItems',
                icon: ReceiptTextIcon,
                permission: 'open-items.read',
            },
            {
                to: '/w/$companyId/allocations',
                label: 'app.navAllocations',
                icon: SplitIcon,
                permission: 'allocations.read',
            },
            {
                to: '/w/$companyId/reconciliations',
                label: 'app.navReconciliations',
                icon: ScaleIcon,
                permission: 'reconciliations.read',
            },
        ],
    },
    {
        label: 'app.navGroupReporting',
        items: [
            {
                to: '/w/$companyId/reports',
                label: 'app.navReports',
                icon: FileTextIcon,
                permission: 'reports.read',
            },
            {
                to: '/w/$companyId/reports/trial-balance',
                label: 'app.navTrialBalance',
                icon: SigmaIcon,
                permission: 'reports.read',
            },
            {
                to: '/w/$companyId/reports/general-ledger',
                label: 'app.navGeneralLedger',
                icon: BookOpenIcon,
                permission: 'reports.read',
            },
            {
                to: '/w/$companyId/reports/aging',
                label: 'app.navAging',
                icon: HourglassIcon,
                permission: 'reports.read',
            },
            {
                to: '/w/$companyId/reports/cash-basis-expenses',
                label: 'app.navCashBasis',
                icon: BanknoteIcon,
                permission: 'reports.read',
            },
        ],
    },
    {
        label: 'app.navGroupPlanning',
        items: [
            {
                to: '/w/$companyId/plans',
                label: 'app.navPlans',
                icon: ClipboardListIcon,
                permission: 'plans.read',
            },
        ],
    },
    {
        label: 'app.navGroupAccess',
        items: [
            {
                to: '/w/$companyId/members',
                label: 'app.navMembers',
                icon: UsersIcon,
                permission: 'memberships.read',
            },
            {
                to: '/w/$companyId/roles',
                label: 'app.navRoles',
                icon: ShieldCheckIcon,
                permission: 'roles.read',
            },
            {
                to: '/w/$companyId/security-policy',
                label: 'app.navSecurityPolicy',
                icon: LockKeyholeIcon,
                permission: 'policies.manage',
            },
            {
                to: '/w/$companyId/integration-clients',
                label: 'app.navIntegrations',
                icon: PlugIcon,
                permission: 'integration-clients.manage',
            },
        ],
    },
] as const satisfies readonly {
    label: string | null;
    items: readonly {
        to: string;
        label: string;
        icon: ComponentType;
        permission: string | null;
    }[];
}[];

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function UserMenu({ me }: { me: Me }) {
    const { t } = useTranslation();
    const signOut = useSignOut();

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        render={
                            <SidebarMenuButton
                                size="lg"
                                aria-label={t('app.openMenu')}
                            />
                        }
                    >
                        <Avatar className="size-8">
                            <AvatarFallback>{initials(me.name)}</AvatarFallback>
                        </Avatar>
                        <div className="grid flex-1 text-left text-sm leading-tight">
                            <span className="truncate font-medium">
                                {me.name}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                                {me.email}
                            </span>
                        </div>
                        <ChevronsUpDownIcon className="ml-auto size-4" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="top"
                        align="start"
                        className="min-w-56"
                    >
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>
                                {t('app.account')}
                            </DropdownMenuLabel>
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            <DropdownMenuItem
                                disabled={signOut.isPending}
                                onClick={() => signOut.mutate()}
                            >
                                {signOut.isPending ? (
                                    <Spinner aria-hidden />
                                ) : (
                                    <LogOutIcon />
                                )}
                                {signOut.isPending
                                    ? t('app.signingOut')
                                    : t('app.signOut')}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

/**
 * Frame of every signed-in screen: collapsible sidebar, workspace switcher, account menu, content
 * area. The sidebar navigates the company of the address (`/w/$companyId/...`); outside one, it
 * only offers the switcher.
 */
export function AppShell({
    me,
    workspaces,
    children,
}: {
    me: Me;
    workspaces: Workspace[];
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const { companyId } = useParams({ strict: false });
    const matchRoute = useMatchRoute();
    const active = workspaces.find((w) => w.company_id === companyId);

    return (
        <SidebarProvider>
            <Sidebar collapsible="icon">
                <SidebarHeader>
                    <WorkspaceSwitcher
                        workspaces={workspaces}
                        active={active}
                    />
                </SidebarHeader>
                <SidebarContent>
                    {/* Groups belong to no company, so they are offered whichever workspace is open (or none). */}
                    <SidebarGroup>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        isActive={Boolean(
                                            matchRoute({
                                                to: '/groups',
                                                fuzzy: true,
                                            }),
                                        )}
                                        render={<Link to="/groups" />}
                                        tooltip={t('app.navGroups')}
                                    >
                                        <NetworkIcon />
                                        <span>{t('app.navGroups')}</span>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                    {active &&
                        NAV.map((group) => ({
                            ...group,
                            items: group.items.filter(
                                (item) =>
                                    !item.permission ||
                                    can(active, item.permission),
                            ),
                        }))
                            .filter((group) => group.items.length > 0)
                            .map((group) => (
                                <SidebarGroup key={group.label ?? 'home'}>
                                    {group.label && (
                                        <SidebarGroupLabel>
                                            {t(group.label)}
                                        </SidebarGroupLabel>
                                    )}
                                    <SidebarGroupContent>
                                        <SidebarMenu>
                                            {group.items.map(
                                                ({ to, label, icon: Icon }) => (
                                                    <SidebarMenuItem key={to}>
                                                        <SidebarMenuButton
                                                            isActive={Boolean(
                                                                matchRoute({
                                                                    to,
                                                                    params: {
                                                                        companyId:
                                                                            active.company_id,
                                                                    },
                                                                }),
                                                            )}
                                                            render={
                                                                <Link
                                                                    to={to}
                                                                    params={{
                                                                        companyId:
                                                                            active.company_id,
                                                                    }}
                                                                />
                                                            }
                                                            tooltip={t(label)}
                                                        >
                                                            <Icon />
                                                            <span>
                                                                {t(label)}
                                                            </span>
                                                        </SidebarMenuButton>
                                                    </SidebarMenuItem>
                                                ),
                                            )}
                                        </SidebarMenu>
                                    </SidebarGroupContent>
                                </SidebarGroup>
                            ))}
                </SidebarContent>
                <SidebarFooter>
                    <UserMenu me={me} />
                </SidebarFooter>
            </Sidebar>

            <SidebarInset>
                <header className="flex h-12 shrink-0 items-center justify-between gap-2 border-b px-4">
                    <div className="flex min-w-0 items-center gap-2">
                        <SidebarTrigger />
                        <Separator orientation="vertical" className="h-4" />
                        <Breadcrumbs />
                    </div>
                    <LanguageSwitcher />
                </header>
                <div className="flex flex-1 flex-col gap-4 p-4 duration-300 motion-safe:animate-in motion-safe:fade-in md:p-6">
                    {children}
                </div>
            </SidebarInset>
        </SidebarProvider>
    );
}
