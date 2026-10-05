import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { FileStackIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SearchableSelect } from '@/components/searchable-select';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { useLastDefined } from '@/hooks/use-last-defined';
import { formatDecimal } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import { FilterSelect } from '@/modules/subledger/components/filter-select';
import {
    DOCUMENT_KINDS,
    documentsQuery,
    partyOptionsQuery,
    type DocumentKind,
    type SubledgerDocument,
} from '@/modules/subledger/documents';

export type DocumentAccess = {
    /** Names and filters by party. */
    parties: boolean;
    /** Links to the journal of a document. */
    journals: boolean;
    /** Links to the entry that recorded a document. */
    entries: boolean;
};

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

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}

function DocumentDetail({
    companyId,
    document,
    partyName,
    access,
    onOpenChange,
}: {
    companyId: string;
    document: SubledgerDocument | null;
    partyName: string | undefined;
    access: DocumentAccess;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, i18n } = useTranslation();
    const shown = useLastDefined(document);

    return (
        <FormDialog
            open={document !== null}
            onOpenChange={onOpenChange}
            size="lg"
            title={t('documents.detailTitle', {
                number: shown?.document_number ?? '',
            })}
            description={t('documents.detailDescription')}
        >
            {shown && (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap gap-2">
                        <Badge variant="secondary">
                            {t(kindLabel(shown.kind))}
                        </Badge>
                        {shown.is_opening && (
                            <Badge variant="outline">
                                {t('documents.opening')}
                            </Badge>
                        )}
                        {shown.reversal_journal_id !== null && (
                            <Badge variant="destructive">
                                {t('documents.reversed', {
                                    date: shown.reversal_date,
                                })}
                            </Badge>
                        )}
                    </div>
                    <dl className="grid gap-4 text-sm sm:grid-cols-2">
                        {partyName !== undefined && (
                            <Fact label={t('documents.party')}>
                                {partyName}
                            </Fact>
                        )}
                        <Fact label={t('documents.documentDate')}>
                            {shown.document_date}
                        </Fact>
                        <Fact label={t('documents.dueDate')}>
                            {shown.due_date}
                        </Fact>
                        <Fact label={t('documents.accountingDate')}>
                            {shown.accounting_date}
                        </Fact>
                        <Fact label={t('documents.amount')}>
                            {formatDecimal(shown.amount, i18n.language)}{' '}
                            {shown.currency}
                        </Fact>
                        <Fact label={t('documents.functionalAmount')}>
                            {formatDecimal(
                                shown.functional_amount,
                                i18n.language,
                            )}
                        </Fact>
                        <Fact label={t('documents.exchangeRate')}>
                            {shown.exchange_rate}
                        </Fact>
                        <Fact label={t('documents.note')}>
                            {shown.description || '—'}
                        </Fact>
                    </dl>
                    <div className="flex flex-wrap gap-3 text-sm">
                        {access.journals && (
                            <Link
                                to="/w/$companyId/journals/$journalId"
                                params={{
                                    companyId,
                                    journalId: shown.journal_id,
                                }}
                                className="underline underline-offset-2"
                            >
                                {t('documents.openJournal')}
                            </Link>
                        )}
                        {access.journals && shown.reversal_journal_id && (
                            <Link
                                to="/w/$companyId/journals/$journalId"
                                params={{
                                    companyId,
                                    journalId: shown.reversal_journal_id,
                                }}
                                className="underline underline-offset-2"
                            >
                                {t('documents.openReversal')}
                            </Link>
                        )}
                        {access.entries && shown.entry_id && (
                            <Link
                                to="/w/$companyId/entries/$entryId"
                                params={{
                                    companyId,
                                    entryId: shown.entry_id,
                                }}
                                className="underline underline-offset-2"
                            >
                                {t('documents.openEntry')}
                            </Link>
                        )}
                    </div>
                </div>
            )}
        </FormDialog>
    );
}

/**
 * Documents of the subledger (SL-FR-04): the invoices, credit notes, payments, advances and
 * write-offs that entries with a subledger route recorded. Read-only here; a reversed document is
 * marked, and an opening document is one of the conversion date.
 */
export function DocumentsPage({
    companyId,
    access,
}: {
    companyId: string;
    access: DocumentAccess;
}) {
    const { t, i18n } = useTranslation();
    const [partyId, setPartyId] = useState('');
    const [kind, setKind] = useState<DocumentKind | undefined>();
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('documents');
    const [selected, setSelected] = useState<SubledgerDocument | null>(null);

    useDocumentTitle(t('documents.pageTitle'));

    const parties = useQuery({
        ...partyOptionsQuery(companyId),
        enabled: access.parties,
    });
    const documents = useQuery(
        documentsQuery(companyId, {
            partyId: partyId || undefined,
            kind,
            dateFrom,
            dateTo,
            page,
            perPage: pageSize,
        }),
    );
    const partyName = new Map(
        (parties.data ?? []).map((party) => [party.id, party.name]),
    );
    const filtered =
        partyId !== '' ||
        kind !== undefined ||
        dateFrom !== '' ||
        dateTo !== '';

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('documents.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('documents.description')}
                </p>
            </div>

            <div className="flex flex-wrap items-end gap-3">
                {access.parties && (
                    <SearchableSelect
                        className="w-56"
                        aria-label={t('documents.party')}
                        items={(parties.data ?? []).map((party) => ({
                            value: party.id,
                            label: party.name,
                        }))}
                        value={partyId}
                        clearable
                        onValueChange={(value) => {
                            setPartyId(value);
                            setPage(1);
                        }}
                        placeholder={t('documents.allParties')}
                        emptyText={t('common.noMatch')}
                    />
                )}
                <FilterSelect
                    label={t('documents.kind')}
                    anyLabel={t('documents.allKinds')}
                    options={DOCUMENT_KINDS.map((value) => ({
                        value,
                        label: t(kindLabel(value)),
                    }))}
                    value={kind}
                    onChange={(value) => {
                        setKind(value);
                        setPage(1);
                    }}
                />
                <div className="flex flex-col gap-1.5">
                    <span
                        id="documents-from-label"
                        className="text-xs text-muted-foreground"
                    >
                        {t('documents.from')}
                    </span>
                    <DatePicker
                        labelledBy="documents-from-label"
                        value={dateFrom}
                        clearable
                        placeholder={t('documents.anyDate')}
                        onChange={(value) => {
                            setDateFrom(value);
                            setPage(1);
                        }}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <span
                        id="documents-to-label"
                        className="text-xs text-muted-foreground"
                    >
                        {t('documents.to')}
                    </span>
                    <DatePicker
                        labelledBy="documents-to-label"
                        value={dateTo}
                        clearable
                        placeholder={t('documents.anyDate')}
                        onChange={(value) => {
                            setDateTo(value);
                            setPage(1);
                        }}
                    />
                </div>
            </div>

            {documents.isError ? (
                <FormAlert>{genericErrorMessage(documents.error, t)}</FormAlert>
            ) : documents.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : documents.data.total === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <FileStackIcon />
                        </EmptyMedia>
                        <EmptyTitle>
                            {t(
                                filtered
                                    ? 'documents.noMatch'
                                    : 'documents.empty',
                            )}
                        </EmptyTitle>
                        <EmptyDescription>
                            {t(
                                filtered
                                    ? 'documents.noMatchDescription'
                                    : 'documents.emptyDescription',
                            )}
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
                                        {t('documents.number')}
                                    </TableHead>
                                    <TableHead>{t('documents.kind')}</TableHead>
                                    {access.parties && (
                                        <TableHead>
                                            {t('documents.party')}
                                        </TableHead>
                                    )}
                                    <TableHead>
                                        {t('documents.documentDate')}
                                    </TableHead>
                                    <TableHead>
                                        {t('documents.dueDate')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('documents.amount')}
                                    </TableHead>
                                    <TableHead>
                                        {t('documents.statusLabel')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.documents.map((document) => (
                                    <TableRow key={document.id}>
                                        <TableCell className="font-medium">
                                            <Button
                                                variant="link"
                                                className="h-auto p-0"
                                                aria-label={t(
                                                    'documents.open',
                                                    {
                                                        number: document.document_number,
                                                    },
                                                )}
                                                onClick={() =>
                                                    setSelected(document)
                                                }
                                            >
                                                {document.document_number}
                                            </Button>
                                        </TableCell>
                                        <TableCell>
                                            {t(kindLabel(document.kind))}
                                        </TableCell>
                                        {access.parties && (
                                            <TableCell>
                                                {partyName.get(
                                                    document.party_id,
                                                ) ?? '—'}
                                            </TableCell>
                                        )}
                                        <TableCell className="tabular-nums">
                                            {document.document_date}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {document.due_date}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {formatDecimal(
                                                document.amount,
                                                i18n.language,
                                            )}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {document.currency}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                {document.is_opening && (
                                                    <Badge variant="outline">
                                                        {t('documents.opening')}
                                                    </Badge>
                                                )}
                                                {document.reversal_journal_id !==
                                                    null && (
                                                    <Badge variant="destructive">
                                                        {t(
                                                            'documents.reversedShort',
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <TablePagination
                        total={documents.data.total}
                        page={documents.data.page}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={(size) => {
                            setPageSize(size);
                            setPage(1);
                        }}
                    />
                </div>
            )}

            <DocumentDetail
                companyId={companyId}
                document={selected}
                partyName={
                    selected && access.parties
                        ? (partyName.get(selected.party_id) ?? '—')
                        : undefined
                }
                access={access}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                    }
                }}
            />
        </>
    );
}
