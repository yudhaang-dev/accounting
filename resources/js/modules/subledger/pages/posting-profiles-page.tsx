import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { PencilIcon, PlusIcon, WorkflowIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyContent,
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
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    allAccountsQuery,
    type Account,
} from '@/modules/general-ledger/accounts';
import { PostingProfileDialog } from '@/modules/subledger/components/posting-profile-dialog';
import {
    CONTROL_ROLES,
    mapControlAccount,
    postingProfilesQuery,
    type ControlRoleKey,
    type ControlRoleRow,
    type PostingProfile,
} from '@/modules/subledger/posting-profiles';

type ControlClass = (typeof CONTROL_ROLES)[number]['accountClass'];

/**
 * An account takes a control role when it is an active leaf of the class the role needs and accepts
 * the `subledger` source only (SL-FR-02, GL-FR-02).
 */
function eligible(account: Account, accountClass: ControlClass): boolean {
    return (
        account.status === 'active' &&
        account.is_leaf &&
        account.class === accountClass &&
        account.allowed_sources.length === 1 &&
        account.allowed_sources[0] === 'subledger'
    );
}

function RoleRow({
    companyId,
    profile,
    role,
    accountClass,
    accounts,
    canManage,
    canManageAccounts,
}: {
    companyId: string;
    profile: PostingProfile;
    role: ControlRoleRow;
    accountClass: ControlClass;
    accounts: Account[];
    canManage: boolean;
    canManageAccounts: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [chosen, setChosen] = useState(role.account_id ?? '');
    const roleName = t(`postingProfiles.roles.${role.key}`);

    const items = accounts
        .filter(
            (account) =>
                eligible(account, accountClass) ||
                account.id === role.account_id,
        )
        .map((account) => ({
            value: account.id,
            label: `${account.code} · ${account.name}`,
        }));
    const hasEligible = accounts.some((account) =>
        eligible(account, accountClass),
    );

    const save = useMutation({
        mutationFn: () =>
            mapControlAccount(
                companyId,
                profile.id,
                role.key,
                chosen,
                role.lock_version,
            ),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'posting-profiles'],
            });
            toast.success(
                t('postingProfiles.mapped', {
                    role: roleName,
                    profile: profile.name,
                }),
            );
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await queryClient.invalidateQueries({
                    queryKey: ['companies', companyId, 'posting-profiles'],
                });
                toast.error(t('postingProfiles.mappingConflict'));

                return;
            }

            toast.error(actionErrorMessage(error, t));
        },
    });

    const status =
        role.account_id === null
            ? 'unmapped'
            : role.valid
              ? 'mapped'
              : 'invalid';

    return (
        <TableRow>
            <TableCell className="align-top">
                <div className="font-medium">{roleName}</div>
                <div className="text-xs text-muted-foreground">
                    {t(`postingProfiles.needs.${accountClass}`)}
                </div>
            </TableCell>
            <TableCell className="min-w-64 align-top">
                <SearchableSelect
                    id={`profile-${profile.id}-${role.key}`}
                    aria-label={`${roleName}, ${profile.name}`}
                    items={items}
                    value={chosen}
                    onValueChange={setChosen}
                    placeholder={t('postingProfiles.choose')}
                    emptyText={t('common.noMatch')}
                    disabled={!canManage}
                />
                {canManage && !hasEligible && (
                    <p className="mt-1.5 max-w-sm text-xs text-muted-foreground">
                        {t('postingProfiles.noEligible', {
                            class: t(`accountClass.${accountClass}`),
                        })}{' '}
                        {canManageAccounts && (
                            <Link
                                to="/w/$companyId/accounts"
                                params={{ companyId }}
                                className="underline underline-offset-2"
                            >
                                {t('postingProfiles.toAccounts')}
                            </Link>
                        )}
                    </p>
                )}
            </TableCell>
            <TableCell className="align-top">
                <Badge
                    variant={
                        status === 'mapped'
                            ? 'default'
                            : status === 'invalid'
                              ? 'destructive'
                              : 'outline'
                    }
                >
                    {t(`postingProfiles.status.${status}`)}
                </Badge>
                {status === 'invalid' && (
                    <p className="mt-1 max-w-48 text-xs text-muted-foreground">
                        {t('postingProfiles.invalidHint')}
                    </p>
                )}
            </TableCell>
            <TableCell className="align-top tabular-nums">
                {role.effective_date ?? '—'}
            </TableCell>
            {canManage && (
                <TableCell className="text-end align-top">
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label={t('postingProfiles.saveMapping', {
                            role: roleName,
                            profile: profile.name,
                        })}
                        disabled={
                            save.isPending ||
                            chosen === '' ||
                            chosen === role.account_id
                        }
                        onClick={() => save.mutate()}
                    >
                        {t('common.save')}
                    </Button>
                </TableCell>
            )}
        </TableRow>
    );
}

function ProfileSection({
    companyId,
    profile,
    accounts,
    canManage,
    canManageAccounts,
    onEdit,
}: {
    companyId: string;
    profile: PostingProfile;
    accounts: Account[];
    canManage: boolean;
    canManageAccounts: boolean;
    onEdit: () => void;
}) {
    const { t } = useTranslation();

    return (
        <section className="flex flex-col gap-3" aria-label={profile.name}>
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-base font-semibold">{profile.name}</h2>
                {profile.is_default && (
                    <Badge variant="secondary">
                        {t('postingProfiles.default')}
                    </Badge>
                )}
                {canManage && (
                    <Button
                        size="sm"
                        variant="ghost"
                        aria-label={t('postingProfiles.editAction', {
                            name: profile.name,
                        })}
                        onClick={onEdit}
                    >
                        <PencilIcon data-icon="inline-start" />
                        {t('postingProfiles.edit')}
                    </Button>
                )}
            </div>
            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('postingProfiles.role')}</TableHead>
                            <TableHead>
                                {t('postingProfiles.account')}
                            </TableHead>
                            <TableHead>
                                {t('postingProfiles.statusLabel')}
                            </TableHead>
                            <TableHead>
                                {t('postingProfiles.effectiveDate')}
                            </TableHead>
                            {canManage && (
                                <TableHead className="text-end">
                                    {t('postingProfiles.actions')}
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {CONTROL_ROLES.map(({ key, accountClass }) => {
                            const role = profile.accounts.find(
                                (row) => row.key === (key as ControlRoleKey),
                            ) ?? {
                                key,
                                account_id: null,
                                effective_date: null,
                                valid: false,
                                lock_version: 0,
                            };

                            return (
                                <RoleRow
                                    // A new version resets what was chosen in the row.
                                    key={`${profile.id}:${key}:${role.lock_version}:${role.account_id}`}
                                    companyId={companyId}
                                    profile={profile}
                                    role={role}
                                    accountClass={accountClass}
                                    accounts={accounts}
                                    canManage={canManage}
                                    canManageAccounts={canManageAccounts}
                                />
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}

/**
 * Posting profiles (SL-FR-02): each decides the receivables and payables control accounts of the
 * parties that use it. The default profile serves parties without one. Each mapping is a new
 * version, so earlier postings keep the account they were made to.
 */
export function PostingProfilesPage({
    companyId,
    canManage,
    canManageAccounts,
}: {
    companyId: string;
    canManage: boolean;
    /** Whether the user may open the chart, where an account is limited to the subledger source. */
    canManageAccounts: boolean;
}) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<PostingProfile | null>(null);

    useDocumentTitle(t('postingProfiles.pageTitle'));

    const profiles = useQuery(postingProfilesQuery(companyId));
    const accounts = useQuery(allAccountsQuery(companyId));
    const addButton = (
        <Button onClick={() => setCreating(true)}>
            <PlusIcon data-icon="inline-start" />
            {t('postingProfiles.add')}
        </Button>
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('postingProfiles.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('postingProfiles.description')}
                    </p>
                </div>
                {canManage && addButton}
            </div>

            {profiles.isError || accounts.isError ? (
                <FormAlert>
                    {genericErrorMessage(profiles.error ?? accounts.error, t)}
                </FormAlert>
            ) : profiles.data === undefined || accounts.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : profiles.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <WorkflowIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('postingProfiles.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('postingProfiles.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && <EmptyContent>{addButton}</EmptyContent>}
                </Empty>
            ) : (
                profiles.data.map((profile) => (
                    <ProfileSection
                        key={profile.id}
                        companyId={companyId}
                        profile={profile}
                        accounts={accounts.data}
                        canManage={canManage}
                        canManageAccounts={canManageAccounts}
                        onEdit={() => setEditing(profile)}
                    />
                ))
            )}

            <PostingProfileDialog
                companyId={companyId}
                open={creating}
                onOpenChange={setCreating}
            />
            <PostingProfileDialog
                companyId={companyId}
                profile={editing ?? undefined}
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            />
        </>
    );
}
