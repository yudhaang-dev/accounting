import { Link } from '@tanstack/react-router';
import {
    BookOpenCheckIcon,
    ChevronsUpDownIcon,
    LayoutListIcon,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
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
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { Workspace } from '@/modules/identity-access/workspaces';

type Props = {
    workspaces: Workspace[];
    /** The company of the screen being viewed, if any. */
    active?: Workspace;
};

/** Top of the sidebar: names the workspace in use and switches to another one. */
export function WorkspaceSwitcher({ workspaces, active }: Props) {
    const { t } = useTranslation();
    const others = workspaces.filter(
        (w) => w.company_id !== active?.company_id,
    );

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        render={
                            <SidebarMenuButton
                                size="lg"
                                aria-label={t('workspace.switch')}
                            />
                        }
                    >
                        <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                            <BookOpenCheckIcon className="size-4" aria-hidden />
                        </div>
                        <div className="grid flex-1 text-left text-sm leading-tight">
                            <span className="truncate font-medium">
                                {active?.name ?? t('common.appName')}
                            </span>
                            {active && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {active.company_name}
                                </span>
                            )}
                        </div>
                        <ChevronsUpDownIcon className="ml-auto size-4" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="min-w-64">
                        {active && (
                            <DropdownMenuGroup>
                                <DropdownMenuLabel>
                                    {t('workspace.current')}
                                </DropdownMenuLabel>
                                <DropdownMenuItem
                                    render={
                                        <Link
                                            to="/w/$companyId"
                                            params={{
                                                companyId: active.company_id,
                                            }}
                                        />
                                    }
                                >
                                    {active.name}
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                        )}
                        {others.length > 0 && (
                            <>
                                {active && <DropdownMenuSeparator />}
                                <DropdownMenuGroup>
                                    <DropdownMenuLabel>
                                        {t('workspace.others')}
                                    </DropdownMenuLabel>
                                    {others.map((workspace) => (
                                        <DropdownMenuItem
                                            key={workspace.id}
                                            render={
                                                <Link
                                                    to="/w/$companyId"
                                                    params={{
                                                        companyId:
                                                            workspace.company_id,
                                                    }}
                                                />
                                            }
                                        >
                                            {workspace.name}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuGroup>
                            </>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            <DropdownMenuItem
                                render={<Link to="/workspaces" />}
                            >
                                <LayoutListIcon />
                                {t('workspace.all')}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
