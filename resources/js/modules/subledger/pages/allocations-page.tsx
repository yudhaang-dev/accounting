import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { PlusIcon, SplitIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
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
import { compareDecimals, formatDecimal } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import {
    allocationsQuery,
    openItemNamesQuery,
    type Allocation,
    type AllocationStatus,
} from '@/modules/subledger/allocations';
import {
    AllocateDialog,
    CancelAllocationDialog,
} from '@/modules/subledger/components/allocation-dialogs';
import { FilterSelect } from '@/modules/subledger/components/filter-select';

export type AllocationAccess = {
    /** Names the two items of an allocation by their documents. */
    openItems: boolean;
    /** Chooses the party of a new allocation. */
    parties: boolean;
    /** Links to the journal of an exchange difference. */
    journals: boolean;
};

/**
 * Allocations (SL-FR-05, SL-FR-06): amounts of a debit open item settled against a credit one of the
 * same party. An allocation makes a journal only for a realized exchange difference. A cancelled
 * allocation keeps its row, with who cancelled it and why, and the items open again.
 */
export function AllocationsPage({
    companyId,
    openItemId,
    canManage,
    access,
}: {
    companyId: string;
    /** Only the allocations of this open item, on either side. */
    openItemId?: string;
    canManage: boolean;
    access: AllocationAccess;
}) {
    const { t, i18n } = useTranslation();
    const [status, setStatus] = useState<AllocationStatus | undefined>();
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('allocations');
    const [creating, setCreating] = useState(false);
    const [cancelling, setCancelling] = useState<Allocation | null>(null);
    const canCreate = canManage && access.openItems && access.parties;

    useDocumentTitle(t('allocations.pageTitle'));

    const allocations = useQuery(
        allocationsQuery(companyId, {
            openItemId,
            status,
            page,
            perPage: pageSize,
        }),
    );
    const names = useQuery({
        ...openItemNamesQuery(companyId),
        enabled: access.openItems,
    });
    const documentNumber = new Map(
        (names.data ?? []).map((item) => [item.id, item.document_number]),
    );
    const itemName = (id: string) => documentNumber.get(id) ?? '—';
    const addButton = (
        <Button onClick={() => setCreating(true)}>
            <PlusIcon data-icon="inline-start" />
            {t('allocations.add')}
        </Button>
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('allocations.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('allocations.description')}
                    </p>
                </div>
                {canCreate && addButton}
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <FilterSelect
                    label={t('allocations.statusLabel')}
                    anyLabel={t('allocations.allStatuses')}
                    options={[
                        { value: 'active', label: t('allocations.active') },
                        {
                            value: 'cancelled',
                            label: t('allocations.cancelledStatus'),
                        },
                    ]}
                    value={status}
                    onChange={(value) => {
                        setStatus(value);
                        setPage(1);
                    }}
                />
                {openItemId !== undefined && (
                    <div className="flex items-center gap-2 text-sm">
                        <Badge variant="secondary">
                            {t('allocations.forItem', {
                                number: itemName(openItemId),
                            })}
                        </Badge>
                        <Link
                            to="/w/$companyId/allocations"
                            params={{ companyId }}
                            className="underline underline-offset-2"
                        >
                            {t('allocations.showAll')}
                        </Link>
                    </div>
                )}
            </div>

            {allocations.isError ? (
                <FormAlert>
                    {genericErrorMessage(allocations.error, t)}
                </FormAlert>
            ) : allocations.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : allocations.data.total === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <SplitIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('allocations.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('allocations.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canCreate && <EmptyContent>{addButton}</EmptyContent>}
                </Empty>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('allocations.date')}
                                    </TableHead>
                                    <TableHead>
                                        {t('allocations.debitItem')}
                                    </TableHead>
                                    <TableHead>
                                        {t('allocations.creditItem')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('allocations.amount')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('allocations.difference')}
                                    </TableHead>
                                    <TableHead>
                                        {t('allocations.statusLabel')}
                                    </TableHead>
                                    {canManage && (
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                {t('allocations.actions')}
                                            </span>
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {allocations.data.allocations.map(
                                    (allocation) => {
                                        const hasDifference =
                                            compareDecimals(
                                                allocation.realized_difference,
                                                '0',
                                            ) !== 0;

                                        return (
                                            <TableRow
                                                key={allocation.id}
                                                className={
                                                    allocation.status ===
                                                    'active'
                                                        ? undefined
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                <TableCell className="tabular-nums">
                                                    {allocation.allocation_date}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {itemName(
                                                        allocation.debit_item_id,
                                                    )}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {itemName(
                                                        allocation.credit_item_id,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums">
                                                    {formatDecimal(
                                                        allocation.amount,
                                                        i18n.language,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums">
                                                    {hasDifference ? (
                                                        <>
                                                            {formatDecimal(
                                                                allocation.realized_difference,
                                                                i18n.language,
                                                            )}
                                                            {access.journals &&
                                                                allocation.journal_id !==
                                                                    null && (
                                                                    <Link
                                                                        to="/w/$companyId/journals/$journalId"
                                                                        params={{
                                                                            companyId,
                                                                            journalId:
                                                                                allocation.journal_id,
                                                                        }}
                                                                        className="ms-2 text-xs underline underline-offset-2"
                                                                    >
                                                                        {t(
                                                                            'allocations.openJournal',
                                                                        )}
                                                                    </Link>
                                                                )}
                                                        </>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {allocation.status ===
                                                    'active' ? (
                                                        <Badge variant="secondary">
                                                            {t(
                                                                'allocations.active',
                                                            )}
                                                        </Badge>
                                                    ) : (
                                                        <div className="flex flex-col gap-0.5">
                                                            <Badge variant="outline">
                                                                {t(
                                                                    'allocations.cancelledOn',
                                                                    {
                                                                        date: allocation.cancelled_on,
                                                                    },
                                                                )}
                                                            </Badge>
                                                            <span className="max-w-56 text-xs">
                                                                {
                                                                    allocation.cancel_reason
                                                                }
                                                            </span>
                                                        </div>
                                                    )}
                                                </TableCell>
                                                {canManage && (
                                                    <TableCell className="text-end">
                                                        {allocation.status ===
                                                            'active' && (
                                                            <RowActions
                                                                label={`${itemName(allocation.debit_item_id)} ${itemName(allocation.credit_item_id)}`}
                                                            >
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    onClick={() =>
                                                                        setCancelling(
                                                                            allocation,
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'allocations.cancelAction',
                                                                    )}
                                                                </DropdownMenuItem>
                                                            </RowActions>
                                                        )}
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        );
                                    },
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    <TablePagination
                        total={allocations.data.total}
                        page={allocations.data.page}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={(size) => {
                            setPageSize(size);
                            setPage(1);
                        }}
                    />
                </div>
            )}

            <AllocateDialog
                companyId={companyId}
                open={creating}
                onOpenChange={setCreating}
            />
            <CancelAllocationDialog
                companyId={companyId}
                allocation={cancelling}
                onClose={() => setCancelling(null)}
            />
        </>
    );
}
