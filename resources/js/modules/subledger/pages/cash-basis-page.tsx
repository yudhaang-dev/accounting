import { useMutation, useQuery } from '@tanstack/react-query';
import { DownloadIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { Button } from '@/components/ui/button';
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
import { todayIso } from '@/lib/date';
import { formatDecimal, sumDecimals } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    cashBasisQuery,
    exportCashBasis,
} from '@/modules/subledger/cash-basis';

/**
 * Cash paid to suppliers over a range, attributed to the expense accounts of the bills it settled
 * (RP-FR-11): the cash counterpart of the accrual figures, with a CSV export.
 */
export function CashBasisPage({ companyId }: { companyId: string }) {
    const { t, i18n } = useTranslation();
    const [draft, setDraft] = useState(() => {
        const to = todayIso();

        return { from: `${to.slice(0, 8)}01`, to };
    });
    const [range, setRange] = useState(draft);

    useDocumentTitle(t('cashBasis.pageTitle'));

    const report = useQuery(cashBasisQuery(companyId, range));
    const download = useMutation({
        mutationFn: () => exportCashBasis(companyId, range),
        onError: (error) =>
            toast.error(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : t('cashBasis.exportFailed'),
            ),
    });

    const money = (value: string) => formatDecimal(value, i18n.language);
    const data = report.data;
    // A dimension set is not a column here, so an account that was spread over several shows once.
    const accounts = useMemo(() => {
        const byAccount = new Map<
            string,
            { id: string; code: string; name: string; amounts: string[] }
        >();

        for (const row of data?.accounts ?? []) {
            const entry = byAccount.get(row.account_id) ?? {
                id: row.account_id,
                code: row.account_code,
                name: row.account_name,
                amounts: [],
            };

            entry.amounts.push(row.cash_paid);
            byAccount.set(row.account_id, entry);
        }

        return [...byAccount.values()];
    }, [data]);

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('cashBasis.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('cashBasis.description')}
                    </p>
                </div>
                <Button
                    variant="outline"
                    disabled={download.isPending || !data}
                    onClick={() => download.mutate()}
                >
                    <DownloadIcon data-icon="inline-start" />
                    {download.isPending
                        ? t('cashBasis.exporting')
                        : t('cashBasis.export')}
                </Button>
            </div>

            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    setRange(draft);
                }}
            >
                <div className="flex flex-col gap-1.5">
                    <span id="cash-basis-from" className="text-sm font-medium">
                        {t('cashBasis.from')}
                    </span>
                    <DatePicker
                        labelledBy="cash-basis-from"
                        value={draft.from}
                        onChange={(from) => setDraft({ ...draft, from })}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <span id="cash-basis-to" className="text-sm font-medium">
                        {t('cashBasis.to')}
                    </span>
                    <DatePicker
                        labelledBy="cash-basis-to"
                        value={draft.to}
                        onChange={(to) => setDraft({ ...draft, to })}
                    />
                </div>
                <Button type="submit">{t('cashBasis.show')}</Button>
            </form>

            {report.isError ? (
                <FormAlert>{genericErrorMessage(report.error, t)}</FormAlert>
            ) : data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('cashBasis.account')}</TableHead>
                                <TableHead className="text-end">
                                    {t('cashBasis.cashPaid', {
                                        currency: data.functional_currency,
                                    })}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {accounts.map((account) => (
                                <TableRow key={account.id}>
                                    <TableCell>
                                        {account.code} · {account.name}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {money(sumDecimals(account.amounts))}
                                    </TableCell>
                                </TableRow>
                            ))}
                            <TableRow>
                                <TableCell className="text-muted-foreground">
                                    {t('cashBasis.otherAccounts')}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(data.other_accounts)}
                                </TableCell>
                            </TableRow>
                            <TableRow>
                                <TableCell className="text-muted-foreground">
                                    {t('cashBasis.unattributed')}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(data.unattributed)}
                                </TableCell>
                            </TableRow>
                            <TableRow className="font-semibold">
                                <TableCell>{t('cashBasis.total')}</TableCell>
                                <TableCell className="text-end tabular-nums">
                                    {money(data.total)}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            )}
        </>
    );
}
