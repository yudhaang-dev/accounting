import { Link } from '@tanstack/react-router';
import {
    BookOpenCheckIcon,
    ChevronsUpDownIcon,
    HouseIcon,
    LogOutIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
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
import { LanguageSwitcher } from '@/components/auth/language-switcher';
import { useSignOut } from '@/hooks/use-sign-out';
import type { Me } from '@/lib/session';

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

/** Frame of every signed-in screen: collapsible sidebar, account menu, content area. */
export function AppShell({ me, children }: { me: Me; children: ReactNode }) {
    const { t } = useTranslation();

    return (
        <SidebarProvider>
            <Sidebar collapsible="icon">
                <SidebarHeader>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                size="lg"
                                render={<Link to="/" />}
                                tooltip={t('common.appName')}
                            >
                                <BookOpenCheckIcon />
                                <span className="font-medium">
                                    {t('common.appName')}
                                </span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarHeader>
                <SidebarContent>
                    <SidebarGroup>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        isActive
                                        render={<Link to="/" />}
                                        tooltip={t('app.navHome')}
                                    >
                                        <HouseIcon />
                                        <span>{t('app.navHome')}</span>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
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
