import { Link, useMatchRoute, useParams } from '@tanstack/react-router';
import {
    CalendarRangeIcon,
    ChevronsUpDownIcon,
    HouseIcon,
    ListTreeIcon,
    LogOutIcon,
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
import { WorkspaceSwitcher } from '@/modules/identity-access/components/workspace-switcher';

/** Company screens in the sidebar; an item shows only for users holding its permission. */
const NAV = [
    {
        to: '/w/$companyId',
        label: 'app.navHome',
        icon: HouseIcon,
        permission: null,
    },
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
] as const satisfies readonly {
    to: string;
    label: string;
    icon: ComponentType;
    permission: string | null;
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
                    {active && (
                        <SidebarGroup>
                            <SidebarGroupContent>
                                <SidebarMenu>
                                    {NAV.filter(
                                        (item) =>
                                            !item.permission ||
                                            can(active, item.permission),
                                    ).map(({ to, label, icon: Icon }) => (
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
                                                <span>{t(label)}</span>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    ))}
                                </SidebarMenu>
                            </SidebarGroupContent>
                        </SidebarGroup>
                    )}
                </SidebarContent>
                <SidebarFooter>
                    <UserMenu me={me} />
                </SidebarFooter>
            </Sidebar>

            <SidebarInset>
                <header className="flex h-12 shrink-0 items-center justify-between gap-2 border-b px-4">
                    <SidebarTrigger />
                    <LanguageSwitcher />
                </header>
                <div className="flex flex-1 flex-col gap-4 p-4 duration-300 motion-safe:animate-in motion-safe:fade-in md:p-6">
                    {children}
                </div>
            </SidebarInset>
        </SidebarProvider>
    );
}
