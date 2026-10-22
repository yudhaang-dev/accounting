import { Link, Outlet } from '@tanstack/react-router';
import { SettingsIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { GroupSettingsDialog } from '@/modules/consolidation/components/group-dialog';
import { groupCan } from '@/modules/consolidation/groups';
import { useGroup } from '@/modules/consolidation/hooks/use-group';

const SECTIONS = [
    { to: '/groups/$groupId/perimeter', label: 'consolidation.nav.perimeter' },
    { to: '/groups/$groupId/accounts', label: 'consolidation.nav.accounts' },
    { to: '/groups/$groupId/mapping', label: 'consolidation.nav.mapping' },
    { to: '/groups/$groupId/runs', label: 'consolidation.nav.runs' },
] as const;

/** The frame of one group: its name, currency and policy, the sections, and the settings for who may run it. */
export function GroupLayout({ groupId }: { groupId: string }) {
    const { t } = useTranslation();
    const group = useGroup(groupId);
    const [settings, setSettings] = useState(false);

    useDocumentTitle(group?.name ?? t('consolidation.pageTitle'));

    if (group === undefined) {
        return null;
    }

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {group.name}
                    </h1>
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <span>
                            {t('consolidation.group.currency')}:{' '}
                            {group.presentation_currency}
                        </span>
                        <Badge variant="outline">
                            {t(
                                `consolidation.policy.${group.package_policy as 'draft' | 'reject'}`,
                            )}
                        </Badge>
                    </div>
                </div>
                {groupCan(group, 'run') && (
                    <Button variant="outline" onClick={() => setSettings(true)}>
                        <SettingsIcon data-icon="inline-start" />
                        {t('consolidation.settings.open')}
                    </Button>
                )}
            </div>

            <nav
                aria-label={t('consolidation.nav.label')}
                className="flex flex-wrap gap-1 border-b"
            >
                {SECTIONS.map(({ to, label }) => (
                    <Link
                        key={to}
                        to={to}
                        params={{ groupId }}
                        className="-mb-px border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground hover:text-foreground"
                        activeProps={{
                            className:
                                'border-primary font-medium text-foreground',
                            'aria-current': 'page',
                        }}
                    >
                        {t(label)}
                    </Link>
                ))}
            </nav>

            <Outlet />

            <GroupSettingsDialog
                group={group}
                open={settings}
                onOpenChange={setSettings}
            />
        </>
    );
}
