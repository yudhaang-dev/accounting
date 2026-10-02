import { useQuery } from '@tanstack/react-query';
import { TablePagination } from '@/components/table-pagination';
import { usePageSize } from '@/lib/page-size';
import { DatePicker } from '@/components/date-picker';
import { Link } from '@tanstack/react-router';
import { FileTextIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
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
import { formatDecimal, sumDecimals } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { useTypeLabel } from '@/modules/transaction-entry/type-labels';
import { EntryStatusBadge } from '@/modules/transaction-entry/components/entry-status-badge';
import {
    ENTRY_STATUSES,
    entriesQuery,
    type EntryStatus,
} from '@/modules/transaction-entry/entries';

const ALL = '__all';

/** Entries of the company (TE-FR-07), newest first, filterable by status and accounting date. */
export function EntriesPage({
    companyId,
    canCreate,
}: {
    companyId: string;
    canCreate: boolean;
}) {
    const { t, i18n } = useTranslation();
    const typeLabel = useTypeLabel(companyId);
    const [status, setStatus] = useState<EntryStatus | undefined>();
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('entries');

    useDocumentTitle(t('entries.pageTitle'));

    const list = useQuery(
        entriesQuery(companyId, { status, from, to, page, perPage: pageSize }),
    );
    const filtered = Boolean(status || from || to);
    const statusItems = [
        { value: ALL, label: t('entries.allStatuses') },
        ...ENTRY_STATUSES.map((value) => ({
            value,
            label: t(`entries.statuses.${value}`),
        })),
    ];

    const newEntry = (
        <Button
            render={
                <Link to="/w/$companyId/entries/new" params={{ companyId }} />
            }
        >
            <PlusIcon data-icon="inline-start" />
            {t('entries.new')}
        </Button>
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('entries.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('entries.description')}
                    </p>
                </div>
                {canCreate && newEntry}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <Field className="w-56">
                    <FieldLabel htmlFor="entries-status">
                        {t('entries.status')}
                    </FieldLabel>
                    <Select
                        value={status ?? ALL}
                        items={statusItems}
                        onValueChange={(value) => {
                            setStatus(
                                value === ALL
                                    ? undefined
                                    : (value as EntryStatus),
                            );
                            setPage(1);
                        }}
                    >
                        <SelectTrigger id="entries-status" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {statusItems.map((item) => (
                                    <SelectItem
                                        key={item.value}
                                        value={item.value}
                                    >
                                        {item.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </Field>
                <Field className="w-56">
                    <FieldLabel id="entries-from-label" htmlFor="entries-from">
                        {t('entries.from')}
                    </FieldLabel>
                    <DatePicker
                        id="entries-from"
                        labelledBy="entries-from-label"
                        value={from}
                        clearable
                        onChange={(value) => {
                            setFrom(value);
                            setPage(1);
                        }}
                    />
                </Field>
                <Field className="w-56">
                    <FieldLabel id="entries-to-label" htmlFor="entries-to">
                        {t('entries.to')}
                    </FieldLabel>
                    <DatePicker
                        id="entries-to"
                        labelledBy="entries-to-label"
                        value={to}
                        clearable
                        onChange={(value) => {
                            setTo(value);
                            setPage(1);
                        }}
                    />
                </Field>
            </div>

            {list.isError ? (
                <FormAlert>{genericErrorMessage(list.error, t)}</FormAlert>
            ) : list.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : list.data.total === 0 && !filtered ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <FileTextIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('entries.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('entries.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canCreate && <EmptyContent>{newEntry}</EmptyContent>}
                </Empty>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('entries.number')}</TableHead>
                                    <TableHead>{t('entries.date')}</TableHead>
                                    <TableHead>{t('entries.memo')}</TableHead>
                                    <TableHead>{t('entries.type')}</TableHead>
                                    <TableHead className="text-end">
                                        {t('entries.amount')}
                                    </TableHead>
                                    <TableHead>{t('entries.status')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.data.entries.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            {t('entries.noMatch')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {list.data.entries.map((entry) => (
                                    <TableRow key={entry.id}>
                                        <TableCell className="font-medium">
                                            <Link
                                                to="/w/$companyId/entries/$entryId"
                                                params={{
                                                    companyId,
                                                    entryId: entry.id,
                                                }}
                                                aria-label={t('entries.open', {
                                                    number: entry.number,
                                                })}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {entry.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {entry.accounting_date}
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate">
                                            {entry.description}
                                        </TableCell>
                                        <TableCell>
                                            {typeLabel(
                                                entry.transaction_type_key,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {formatDecimal(
                                                sumDecimals(
                                                    entry.lines.map(
                                                        (line) => line.debit,
                                                    ),
                                                ),
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <EntryStatusBadge
                                                status={entry.status}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <TablePagination
                        total={list.data.total}
                        page={list.data.page}
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
