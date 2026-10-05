import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, UsersRoundIcon } from 'lucide-react';
import { useDeferredValue, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import { useLastDefined } from '@/hooks/use-last-defined';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import { PartyDialog } from '@/modules/subledger/components/party-dialog';
import {
    partiesQuery,
    setPartyStatus,
    type Party,
} from '@/modules/subledger/parties';
import { postingProfilesQuery } from '@/modules/subledger/posting-profiles';

const ANY = '__any';

/**
 * Parties of the company (SL-FR-01): customers and suppliers, with the references source systems use
 * for them (SL-AC-08) and the posting profile that decides their control accounts. A party is never
 * deleted; it is deactivated and keeps its history.
 */
export function PartiesPage({
    companyId,
    canManage,
    canReadProfiles,
}: {
    companyId: string;
    canManage: boolean;
    /** Whether the user may list posting profiles, to name each party's profile and choose one. */
    canReadProfiles: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [search, setSearch] = useState('');
    const [role, setRole] = useState<'customer' | 'supplier' | undefined>();
    const [status, setStatus] = useState<'active' | 'inactive' | undefined>();
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('parties');
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Party | null>(null);
    const [deactivating, setDeactivating] = useState<Party | null>(null);
    const asking = useLastDefined(deactivating);
    const deferredSearch = useDeferredValue(search.trim());

    useDocumentTitle(t('parties.pageTitle'));

    const parties = useQuery(
        partiesQuery(companyId, {
            search: deferredSearch,
            role,
            status,
            page,
            perPage: pageSize,
        }),
    );
    const profiles = useQuery({
        ...postingProfilesQuery(companyId),
        enabled: canReadProfiles,
    });
    const profileName = new Map(
        (profiles.data ?? []).map((profile) => [profile.id, profile.name]),
    );

    const roleItems = [
        { value: ANY, label: t('parties.allRoles') },
        { value: 'customer', label: t('parties.customer') },
        { value: 'supplier', label: t('parties.supplier') },
    ];
    const statusItems = [
        { value: ANY, label: t('parties.allStatuses') },
        { value: 'active', label: t('parties.active') },
        { value: 'inactive', label: t('parties.inactive') },
    ];
    const filtered =
        deferredSearch !== '' || role !== undefined || status !== undefined;

    const toggle = useMutation({
        mutationFn: (party: Party) =>
            setPartyStatus(
                companyId,
                party.id,
                party.status === 'active' ? 'deactivate' : 'reactivate',
            ),
        onSuccess: async (party) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'parties'],
            });
            toast.success(
                t(
                    party.status === 'active'
                        ? 'parties.reactivated'
                        : 'parties.deactivated',
                    { name: party.name },
                ),
            );
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setDeactivating(null),
    });

    const addButton = (
        <Button onClick={() => setCreating(true)}>
            <PlusIcon data-icon="inline-start" />
            {t('parties.add')}
        </Button>
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('parties.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('parties.description')}
                    </p>
                </div>
                {canManage && addButton}
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <Input
                    type="search"
                    className="w-64"
                    value={search}
                    placeholder={t('parties.searchPlaceholder')}
                    aria-label={t('parties.search')}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setPage(1);
                    }}
                />
                <Select
                    value={role ?? ANY}
                    items={roleItems}
                    onValueChange={(value) => {
                        setRole(
                            value === 'customer' || value === 'supplier'
                                ? value
                                : undefined,
                        );
                        setPage(1);
                    }}
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label={t('parties.roles')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {roleItems.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <Select
                    value={status ?? ANY}
                    items={statusItems}
                    onValueChange={(value) => {
                        setStatus(
                            value === 'active' || value === 'inactive'
                                ? value
                                : undefined,
                        );
                        setPage(1);
                    }}
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label={t('parties.status')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {statusItems.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>

            {parties.isError ? (
                <FormAlert>{genericErrorMessage(parties.error, t)}</FormAlert>
            ) : parties.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : parties.data.total === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <UsersRoundIcon />
                        </EmptyMedia>
                        <EmptyTitle>
                            {t(filtered ? 'parties.noMatch' : 'parties.empty')}
                        </EmptyTitle>
                        <EmptyDescription>
                            {t(
                                filtered
                                    ? 'parties.noMatchDescription'
                                    : 'parties.emptyDescription',
                            )}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && !filtered && (
                        <EmptyContent>{addButton}</EmptyContent>
                    )}
                </Empty>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('parties.name')}</TableHead>
                                    <TableHead>{t('parties.roles')}</TableHead>
                                    <TableHead>{t('parties.taxId')}</TableHead>
                                    <TableHead>
                                        {t('parties.refs.title')}
                                    </TableHead>
                                    {canReadProfiles && (
                                        <TableHead>
                                            {t('parties.profile')}
                                        </TableHead>
                                    )}
                                    <TableHead>{t('parties.status')}</TableHead>
                                    {canManage && (
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                {t('parties.actions')}
                                            </span>
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {parties.data.parties.map((party) => (
                                    <TableRow
                                        key={party.id}
                                        className={
                                            party.status === 'active'
                                                ? undefined
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <TableCell className="font-medium">
                                            {party.name}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                {party.is_customer && (
                                                    <Badge variant="secondary">
                                                        {t('parties.customer')}
                                                    </Badge>
                                                )}
                                                {party.is_supplier && (
                                                    <Badge variant="secondary">
                                                        {t('parties.supplier')}
                                                    </Badge>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {party.tax_id ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {party.external_refs.length ===
                                            0 ? (
                                                '—'
                                            ) : (
                                                <ul className="flex flex-col gap-0.5">
                                                    {party.external_refs.map(
                                                        (ref) => (
                                                            <li
                                                                key={`${ref.source_system}/${ref.external_ref}`}
                                                                className="font-mono text-xs"
                                                            >
                                                                <span className="text-muted-foreground">
                                                                    {
                                                                        ref.source_system
                                                                    }
                                                                </span>{' '}
                                                                {
                                                                    ref.external_ref
                                                                }
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </TableCell>
                                        {canReadProfiles && (
                                            <TableCell>
                                                {party.posting_profile_id ===
                                                null
                                                    ? t(
                                                          'parties.defaultProfile',
                                                      )
                                                    : (profileName.get(
                                                          party.posting_profile_id,
                                                      ) ?? '—')}
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    party.status === 'active'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {t(
                                                    party.status === 'active'
                                                        ? 'parties.active'
                                                        : 'parties.inactive',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-end">
                                                <RowActions label={party.name}>
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setEditing(party)
                                                        }
                                                    >
                                                        {t(
                                                            'parties.editAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                    {party.status ===
                                                    'active' ? (
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onClick={() =>
                                                                setDeactivating(
                                                                    party,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'parties.deactivateAction',
                                                            )}
                                                        </DropdownMenuItem>
                                                    ) : (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                toggle.mutate(
                                                                    party,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'parties.reactivateAction',
                                                            )}
                                                        </DropdownMenuItem>
                                                    )}
                                                </RowActions>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <TablePagination
                        total={parties.data.total}
                        page={parties.data.page}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={(size) => {
                            setPageSize(size);
                            setPage(1);
                        }}
                    />
                </div>
            )}

            <PartyDialog
                companyId={companyId}
                canReadProfiles={canReadProfiles}
                open={creating}
                onOpenChange={setCreating}
            />
            <PartyDialog
                companyId={companyId}
                canReadProfiles={canReadProfiles}
                party={editing ?? undefined}
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            />
            <ConfirmDialog
                open={deactivating !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeactivating(null);
                    }
                }}
                title={t('confirm.deactivateParty.title', {
                    name: asking?.name ?? '',
                })}
                description={t('confirm.deactivateParty.description')}
                confirmLabel={t('confirm.deactivateParty.action')}
                pending={toggle.isPending}
                destructive
                onConfirm={() => deactivating && toggle.mutate(deactivating)}
            />
        </>
    );
}
