import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import {
    ClipboardListIcon,
    PencilIcon,
    PlusIcon,
    SettingsIcon,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { fiscalYearsQuery } from '@/modules/general-ledger/calendar';
import { PlanDialog } from '@/modules/planning/components/plan-dialog';
import { PlanningPolicyDialog } from '@/modules/planning/components/planning-policy-dialog';
import { PublishDialog } from '@/modules/planning/components/publish-dialog';
import {
    VersionDialog,
    type VersionDialogMode,
} from '@/modules/planning/components/version-dialog';
import {
    planningPolicyQuery,
    plansQuery,
    versionsQuery,
    type Plan,
    type PlanVersion,
} from '@/modules/planning/plans';

/**
 * Plans of the company with their versions (PL-FR-01). A draft is opened to fill it in and can be
 * published (PL-FR-04); a published version never changes, so changing it means a revision, a new
 * draft that copies it (PL-AC-01). The default comparison version of each year is marked.
 */
export function PlansPage({
    companyId,
    canManage,
    canPublish,
    canManagePolicy,
}: {
    companyId: string;
    canManage: boolean;
    canPublish: boolean;
    canManagePolicy: boolean;
}) {
    const { t } = useTranslation();
    const [planDialog, setPlanDialog] = useState<{ plan?: Plan } | undefined>();
    const [versionMode, setVersionMode] = useState<VersionDialogMode | null>(
        null,
    );
    const [publishing, setPublishing] = useState<PlanVersion | null>(null);
    const [policyOpen, setPolicyOpen] = useState(false);

    useDocumentTitle(t('plans.pageTitle'));

    const plans = useQuery(plansQuery(companyId));
    const versions = useQuery(versionsQuery(companyId));
    const years = useQuery(fiscalYearsQuery(companyId));
    const policy = useQuery(planningPolicyQuery(companyId));

    const yearName = new Map((years.data ?? []).map((y) => [y.id, y.name]));
    const defaultIds = new Set(
        (policy.data?.default_versions ?? []).map((d) => d.plan_version_id),
    );
    const failure = plans.error ?? versions.error;

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('plans.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('plans.description')}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {canManagePolicy && (
                        <Button
                            variant="outline"
                            onClick={() => setPolicyOpen(true)}
                            disabled={
                                plans.data === undefined ||
                                versions.data === undefined ||
                                years.data === undefined
                            }
                        >
                            <SettingsIcon data-icon="inline-start" />
                            {t('plans.policy.open')}
                        </Button>
                    )}
                    {canManage && (
                        <Button onClick={() => setPlanDialog({})}>
                            <PlusIcon data-icon="inline-start" />
                            {t('plans.plan.create')}
                        </Button>
                    )}
                </div>
            </div>

            {failure ? (
                <FormAlert>{genericErrorMessage(failure, t)}</FormAlert>
            ) : plans.data === undefined || versions.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : plans.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ClipboardListIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('plans.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('plans.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && (
                        <Button onClick={() => setPlanDialog({})}>
                            <PlusIcon data-icon="inline-start" />
                            {t('plans.plan.create')}
                        </Button>
                    )}
                </Empty>
            ) : (
                <div className="flex flex-col gap-4">
                    {plans.data.map((plan) => (
                        <PlanCard
                            key={plan.id}
                            companyId={companyId}
                            plan={plan}
                            yearName={yearName.get(plan.fiscal_year_id)}
                            versions={versions.data.filter(
                                (version) => version.plan_id === plan.id,
                            )}
                            defaultIds={defaultIds}
                            canManage={canManage}
                            canPublish={canPublish}
                            onEdit={() => setPlanDialog({ plan })}
                            onVersion={setVersionMode}
                            onPublish={setPublishing}
                        />
                    ))}
                </div>
            )}

            <PlanDialog
                companyId={companyId}
                plan={planDialog?.plan}
                open={planDialog !== undefined}
                onOpenChange={(open) => !open && setPlanDialog(undefined)}
            />
            <VersionDialog
                companyId={companyId}
                mode={versionMode}
                open={versionMode !== null}
                onOpenChange={(open) => !open && setVersionMode(null)}
            />
            <PublishDialog
                companyId={companyId}
                version={publishing}
                onOpenChange={(open) => !open && setPublishing(null)}
            />
            {canManagePolicy && (
                <PlanningPolicyDialog
                    companyId={companyId}
                    years={years.data ?? []}
                    plans={plans.data ?? []}
                    versions={versions.data ?? []}
                    open={policyOpen}
                    onOpenChange={setPolicyOpen}
                />
            )}
        </>
    );
}

const STATUS_VARIANT = {
    draft: 'outline',
    published: 'default',
    archived: 'secondary',
} as const;

function PlanCard({
    companyId,
    plan,
    yearName,
    versions,
    defaultIds,
    canManage,
    canPublish,
    onEdit,
    onVersion,
    onPublish,
}: {
    companyId: string;
    plan: Plan;
    yearName: string | undefined;
    versions: PlanVersion[];
    defaultIds: Set<string>;
    canManage: boolean;
    canPublish: boolean;
    onEdit: () => void;
    onVersion: (mode: VersionDialogMode) => void;
    onPublish: (version: PlanVersion) => void;
}) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle>{plan.name}</CardTitle>
                <CardDescription>
                    {[yearName, plan.description].filter(Boolean).join(' · ')}
                </CardDescription>
                {canManage && (
                    <CardAction className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            aria-label={t('plans.plan.edit', {
                                name: plan.name,
                            })}
                            onClick={onEdit}
                        >
                            <PencilIcon data-icon="inline-start" />
                            {t('plans.plan.editShort')}
                        </Button>
                        <Button
                            size="sm"
                            aria-label={t('plans.versions.addFor', {
                                name: plan.name,
                            })}
                            onClick={() =>
                                onVersion({ kind: 'create', planId: plan.id })
                            }
                        >
                            <PlusIcon data-icon="inline-start" />
                            {t('plans.versions.add')}
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent>
                {versions.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('plans.versions.none')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('plans.versions.name')}
                                </TableHead>
                                <TableHead>
                                    {t('plans.versions.status')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('plans.versions.lines')}
                                </TableHead>
                                <TableHead>
                                    <span className="sr-only">
                                        {t('plans.versions.actions')}
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {versions.map((version) => (
                                <VersionRow
                                    key={version.id}
                                    companyId={companyId}
                                    version={version}
                                    isDefault={defaultIds.has(version.id)}
                                    origin={versions.find(
                                        (v) =>
                                            v.id === version.origin_version_id,
                                    )}
                                    canManage={canManage}
                                    canPublish={canPublish}
                                    onVersion={onVersion}
                                    onPublish={onPublish}
                                />
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function VersionRow({
    companyId,
    version,
    isDefault,
    origin,
    canManage,
    canPublish,
    onVersion,
    onPublish,
}: {
    companyId: string;
    version: PlanVersion;
    isDefault: boolean;
    origin: PlanVersion | undefined;
    canManage: boolean;
    canPublish: boolean;
    onVersion: (mode: VersionDialogMode) => void;
    onPublish: (version: PlanVersion) => void;
}) {
    const { t } = useTranslation();
    const draft = version.status === 'draft';
    const published = version.status === 'published';
    const status = version.status as keyof typeof STATUS_VARIANT;

    return (
        <TableRow>
            <TableCell>
                <Link
                    to="/w/$companyId/plan-versions/$versionId"
                    params={{ companyId, versionId: version.id }}
                    className="font-medium underline-offset-4 hover:underline"
                >
                    {version.name}
                </Link>
                {origin && (
                    <p className="text-xs text-muted-foreground">
                        {t('plans.versions.origin', { name: origin.name })}
                    </p>
                )}
            </TableCell>
            <TableCell>
                <div className="flex flex-wrap gap-1">
                    <Badge variant={STATUS_VARIANT[status] ?? 'outline'}>
                        {t(`plans.status.${version.status}`, version.status)}
                    </Badge>
                    {isDefault && (
                        <Badge variant="secondary">
                            {t('plans.versions.default')}
                        </Badge>
                    )}
                </div>
            </TableCell>
            <TableCell className="text-right tabular-nums">
                {version.lines_count}
            </TableCell>
            <TableCell className="text-right">
                <RowActions label={version.name}>
                    <DropdownMenuItem
                        render={
                            <Link
                                to="/w/$companyId/plan-versions/$versionId"
                                params={{ companyId, versionId: version.id }}
                            />
                        }
                    >
                        {draft && canManage
                            ? t('plans.versions.edit')
                            : t('plans.versions.view')}
                    </DropdownMenuItem>
                    {draft && canManage && (
                        <DropdownMenuItem
                            onClick={() =>
                                onVersion({ kind: 'rename', version })
                            }
                        >
                            {t('plans.versions.rename')}
                        </DropdownMenuItem>
                    )}
                    {draft && canPublish && (
                        <DropdownMenuItem onClick={() => onPublish(version)}>
                            {t('plans.versions.publish')}
                        </DropdownMenuItem>
                    )}
                    {published && canManage && (
                        <DropdownMenuItem
                            onClick={() =>
                                onVersion({ kind: 'revise', version })
                            }
                        >
                            {t('plans.versions.revise')}
                        </DropdownMenuItem>
                    )}
                </RowActions>
            </TableCell>
        </TableRow>
    );
}
