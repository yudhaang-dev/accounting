import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import {
    FileSpreadsheetIcon,
    TableColumnsSplitIcon,
    UploadIcon,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
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
import { formatMoney } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import { bankStatementsQuery } from '@/modules/subledger/bank-statements';
import { cashAccountsQuery, type CashAccount } from '@/modules/subledger/cash';
import { BankImportDialog } from '@/modules/subledger/components/bank-import-dialog';
import { BankMappingDialog } from '@/modules/subledger/components/bank-mapping-dialog';

const ANY = '__any';

/**
 * Imported bank statements (SL-FR-13), newest first, with the dialogs to save the column mapping of an account
 * and to import a statement. A statement opens to its lines, matching and report.
 */
export function BankStatementsPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t, i18n } = useTranslation();
    const navigate = useNavigate();
    const [accountId, setAccountId] = useState<string | undefined>();
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('bank-statements');
    const [importing, setImporting] = useState(false);
    const [mapping, setMapping] = useState<CashAccount | null>(null);

    useDocumentTitle(t('bankStatements.pageTitle'));

    const cashAccounts = useQuery(cashAccountsQuery(companyId));
    const statements = useQuery(
        bankStatementsQuery(companyId, {
            cashAccountId: accountId,
            page,
            perPage: pageSize,
        }),
    );
    const accounts = cashAccounts.data ?? [];
    const byId = new Map(accounts.map((account) => [account.id, account]));
    const accountItems = [
        { value: ANY, label: t('bankStatements.allAccounts') },
        ...accounts.map((account) => ({
            value: account.id,
            label: `${account.name} (${account.currency})`,
        })),
    ];
    const mappable =
        accountId !== undefined
            ? byId.get(accountId)
            : accounts.length === 1
              ? accounts[0]
              : undefined;

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('bankStatements.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('bankStatements.description')}
                </p>
            </div>

            <div className="flex flex-wrap items-end justify-between gap-3">
                <Select
                    value={accountId ?? ANY}
                    items={accountItems}
                    onValueChange={(value) => {
                        setAccountId(
                            value === ANY || value === null ? undefined : value,
                        );
                        setPage(1);
                    }}
                >
                    <SelectTrigger
                        className="w-64"
                        aria-label={t('bankStatements.account')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {accountItems.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>

                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={mappable === undefined}
                            title={
                                mappable === undefined
                                    ? t('bankStatements.mappingNeedsAccount')
                                    : undefined
                            }
                            onClick={() => setMapping(mappable ?? null)}
                        >
                            <TableColumnsSplitIcon data-icon="inline-start" />
                            {t('bankStatements.columnMapping')}
                        </Button>
                        <Button onClick={() => setImporting(true)}>
                            <UploadIcon data-icon="inline-start" />
                            {t('bankStatements.importAction')}
                        </Button>
                    </div>
                )}
            </div>

            {statements.isError ? (
                <FormAlert>
                    {genericErrorMessage(statements.error, t)}
                </FormAlert>
            ) : statements.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : statements.data.total === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <FileSpreadsheetIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('bankStatements.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('bankStatements.emptyDescription')}
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
                                        {t('bankStatements.account')}
                                    </TableHead>
                                    <TableHead>
                                        {t('bankStatements.file')}
                                    </TableHead>
                                    <TableHead>
                                        {t('bankStatements.period')}
                                    </TableHead>
                                    <TableHead>
                                        {t('bankStatements.closingDate')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('bankStatements.closingBalance')}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t('bankStatements.matched')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {statements.data.statements.map((statement) => {
                                    const account = byId.get(
                                        statement.cash_account_id,
                                    );

                                    return (
                                        <TableRow key={statement.id}>
                                            <TableCell className="font-medium">
                                                <Link
                                                    to="/w/$companyId/bank-statements/$statementId"
                                                    params={{
                                                        companyId,
                                                        statementId:
                                                            statement.id,
                                                    }}
                                                    className="underline underline-offset-2"
                                                >
                                                    {account?.name ?? '—'}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                {statement.file_name ?? '—'}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {statement.period_from} –{' '}
                                                {statement.period_to}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {statement.closing_date}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {formatMoney(
                                                    statement.closing_balance,
                                                    i18n.language,
                                                )}{' '}
                                                <span className="text-xs text-muted-foreground">
                                                    {account?.currency}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">
                                                {statement.matched_count} /{' '}
                                                {statement.line_count}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>

                    <TablePagination
                        total={statements.data.total}
                        page={statements.data.page}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={(size) => {
                            setPageSize(size);
                            setPage(1);
                        }}
                    />
                </div>
            )}

            <BankImportDialog
                companyId={companyId}
                open={importing}
                cashAccounts={accounts.filter(
                    (account) => account.status === 'active',
                )}
                initialAccountId={mappable?.id ?? ''}
                canMap={canManage}
                onMap={(account) => {
                    setImporting(false);
                    setMapping(account);
                }}
                onOpenChange={setImporting}
                onImported={(statement) =>
                    void navigate({
                        to: '/w/$companyId/bank-statements/$statementId',
                        params: { companyId, statementId: statement.id },
                    })
                }
            />
            <BankMappingDialog
                companyId={companyId}
                cashAccount={mapping}
                onOpenChange={(open) => {
                    if (!open) {
                        setMapping(null);
                    }
                }}
            />
        </>
    );
}
