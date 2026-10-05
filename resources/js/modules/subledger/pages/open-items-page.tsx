import { useQuery } from '@tanstack/react-query';
import { ReceiptTextIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
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
import { formatDecimal } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import { FilterSelect } from '@/modules/subledger/components/filter-select';
import {
    CONTROL_ROLE_KEYS,
    DOCUMENT_KINDS,
    OPEN_ITEM_STATUSES,
    openItemsQuery,
    partyOptionsQuery,
    type ControlRoleKey,
    type DocumentKind,
    type OpenItemStatus,
} from '@/modules/subledger/documents';

// The OpenAPI document types these enums as plain strings (a generator limit); the API sends the cases.
const kindLabel = (value: string) =>
    (
        ({
            invoice: 'documents.kinds.invoice',
            credit_note: 'documents.kinds.credit_note',
            payment: 'documents.kinds.payment',
            advance: 'documents.kinds.advance',
            write_off: 'documents.kinds.write_off',
        }) as const
    )[value as DocumentKind];

const roleLabel = (value: string) =>
    (
        ({
            receivables_control: 'openItems.roles.receivables_control',
            payables_control: 'openItems.roles.payables_control',
        }) as const
    )[value as ControlRoleKey];

const statusLabel = (value: string) =>
    (
        ({
            open: 'openItems.statuses.open',
            settled: 'openItems.statuses.settled',
            reversed: 'openItems.statuses.reversed',
        }) as const
    )[value as OpenItemStatus];

/**
 * Open items (SL-FR-04): what each document still owes or is owed, in the currency of the document
 * and in the functional currency. They are created and settled by documents and allocations, so the
 * list is read-only; a reversed document leaves its item `reversed`.
 */
export function OpenItemsPage({
    companyId,
    canReadParties,
}: {
    companyId: string;
    /** Names and filters by party. */
    canReadParties: boolean;
}) {
    const { t, i18n } = useTranslation();
    const [partyId, setPartyId] = useState('');
    const [role, setRole] = useState<ControlRoleKey | undefined>();
    const [kind, setKind] = useState<DocumentKind | undefined>();
    const [status, setStatus] = useState<OpenItemStatus | undefined>('open');
    const [dueTo, setDueTo] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('open-items');

    useDocumentTitle(t('openItems.pageTitle'));

    const parties = useQuery({
        ...partyOptionsQuery(companyId),
        enabled: canReadParties,
    });
    const items = useQuery(
        openItemsQuery(companyId, {
            partyId: partyId || undefined,
            controlRole: role,
            kind,
            status,
            dueTo,
            page,
            perPage: pageSize,
        }),
    );
    const partyName = new Map(
        (parties.data ?? []).map((party) => [party.id, party.name]),
    );
    const reset = () => setPage(1);

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('openItems.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('openItems.description')}
                </p>
            </div>

            <div className="flex flex-wrap items-end gap-3">
                {canReadParties && (
                    <SearchableSelect
                        className="w-56"
                        aria-label={t('openItems.party')}
                        items={(parties.data ?? []).map((party) => ({
                            value: party.id,
                            label: party.name,
                        }))}
                        value={partyId}
                        clearable
                        onValueChange={(value) => {
                            setPartyId(value);
                            reset();
                        }}
                        placeholder={t('openItems.allParties')}
                        emptyText={t('common.noMatch')}
                    />
                )}
                <FilterSelect
                    label={t('openItems.role')}
                    anyLabel={t('openItems.allRoles')}
                    className="w-56"
                    options={CONTROL_ROLE_KEYS.map((value) => ({
                        value,
                        label: t(roleLabel(value)),
                    }))}
                    value={role}
                    onChange={(value) => {
                        setRole(value);
                        reset();
                    }}
                />
                <FilterSelect
                    label={t('openItems.kind')}
                    anyLabel={t('documents.allKinds')}
                    options={DOCUMENT_KINDS.map((value) => ({
                        value,
                        label: t(kindLabel(value)),
                    }))}
                    value={kind}
                    onChange={(value) => {
                        setKind(value);
                        reset();
                    }}
                />
                <FilterSelect
                    label={t('openItems.statusLabel')}
                    anyLabel={t('openItems.allStatuses')}
                    options={OPEN_ITEM_STATUSES.map((value) => ({
                        value,
                        label: t(statusLabel(value)),
                    }))}
                    value={status}
                    onChange={(value) => {
                        setStatus(value);
                        reset();
                    }}
                />
                <div className="flex flex-col gap-1.5">
                    <span
                        id="open-items-due-label"
                        className="text-xs text-muted-foreground"
                    >
                        {t('openItems.dueTo')}
                    </span>
                    <DatePicker
                        labelledBy="open-items-due-label"
                        value={dueTo}
                        clearable
                        placeholder={t('documents.anyDate')}
                        onChange={(value) => {
                            setDueTo(value);
                            reset();
                        }}
                    />
                </div>
            </div>

            {items.isError ? (
                <FormAlert>{genericErrorMessage(items.error, t)}</FormAlert>
            ) : items.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : items.data.total === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ReceiptTextIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('openItems.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('openItems.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('openItems.number')}
                                    </TableHead>
                                    {canReadParties && (
                                        <TableHead>
                                            {t('openItems.party')}
                                        </TableHead>
                                    )}
                                    <TableHead>{t('openItems.kind')}</TableHead>
                                    <TableHead>{t('openItems.role')}</TableHead>
                                    <TableHead>
                                        {t('openItems.dueDate')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('openItems.original')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('openItems.remaining')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('openItems.remainingFunctional')}
                                    </TableHead>
                                    <TableHead>
                                        {t('openItems.statusLabel')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.data.items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium">
                                            {item.document_number}
                                        </TableCell>
                                        {canReadParties && (
                                            <TableCell>
                                                {partyName.get(item.party_id) ??
                                                    '—'}
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            {t(kindLabel(item.kind))}
                                        </TableCell>
                                        <TableCell>
                                            {t(roleLabel(item.control_role))}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {item.due_date}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {formatDecimal(
                                                item.original_amount,
                                                i18n.language,
                                            )}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {item.currency}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {formatDecimal(
                                                item.remaining_amount,
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {formatDecimal(
                                                item.remaining_functional,
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    item.status === 'open'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {t(statusLabel(item.status))}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <TablePagination
                        total={items.data.total}
                        page={items.data.page}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={(size) => {
                            setPageSize(size);
                            setPage(1);
                        }}
                    />
                </div>
            )}
        </>
    );
}
