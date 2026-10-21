import { useMutation, useQuery } from '@tanstack/react-query';
import { DownloadIcon, SettingsIcon } from 'lucide-react';
import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
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
import { formatDecimal } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    agingQuery,
    exportAging,
    type AgingBasis,
    type AgingBucket,
    type ControlRole,
} from '@/modules/subledger/aging';
import { AgingPolicyDialog } from '@/modules/subledger/components/aging-policy-dialog';
import { partyOptionsQuery } from '@/modules/subledger/documents';
import { Link } from '@tanstack/react-router';

const ROLES: ControlRole[] = ['receivables_control', 'payables_control'];

/**
 * Receivables or payables by age (SL-FR-08, SL-AC-04): per party and document, in the transaction and
 * the functional currency, as of any date, with the buckets of the aging policy and a CSV export.
 */
export function AgingPage({
    companyId,
    canManagePolicy,
    canReadParties,
    canReadOpenItems,
}: {
    companyId: string;
    canManagePolicy: boolean;
    canReadParties: boolean;
    canReadOpenItems: boolean;
}) {
    const { t, i18n } = useTranslation();
    const [draftDate, setDraftDate] = useState(todayIso());
    const [asOf, setAsOf] = useState(todayIso());
    const [controlRole, setControlRole] = useState<ControlRole>(
        'receivables_control',
    );
    const [partyId, setPartyId] = useState('');
    const [basis, setBasis] = useState<AgingBasis | undefined>();
    const [editingPolicy, setEditingPolicy] = useState(false);

    useDocumentTitle(t('aging.pageTitle'));

    const filters = {
        asOf,
        controlRole,
        partyId: partyId === '' ? undefined : partyId,
        basis,
    };
    const report = useQuery(agingQuery(companyId, filters));
    const parties = useQuery({
        ...partyOptionsQuery(companyId),
        enabled: canReadParties,
    });
    const download = useMutation({
        mutationFn: () => exportAging(companyId, filters),
        onError: (error) =>
            toast.error(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : t('aging.exportFailed'),
            ),
    });

    const money = (value: string) => formatDecimal(value, i18n.language);
    const bucketLabel = (bucket: AgingBucket) =>
        bucket.key === 'current'
            ? t('aging.bucketCurrent')
            : bucket.to_days === null
              ? t('aging.bucketOver', { to: (bucket.from_days ?? 1) - 1 })
              : t('aging.bucketRange', {
                    from: bucket.from_days,
                    to: bucket.to_days,
                });
    const data = report.data;

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('aging.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('aging.description')}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {canManagePolicy && (
                        <Button
                            variant="outline"
                            onClick={() => setEditingPolicy(true)}
                        >
                            <SettingsIcon data-icon="inline-start" />
                            {t('aging.policy')}
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        disabled={download.isPending || !data}
                        onClick={() => download.mutate()}
                    >
                        <DownloadIcon data-icon="inline-start" />
                        {download.isPending
                            ? t('aging.exporting')
                            : t('aging.export')}
                    </Button>
                </div>
            </div>

            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    setAsOf(draftDate);
                }}
            >
                <div
                    role="group"
                    aria-label={t('aging.role')}
                    className="flex gap-1"
                >
                    {ROLES.map((role) => (
                        <Button
                            key={role}
                            type="button"
                            variant={
                                controlRole === role ? 'default' : 'outline'
                            }
                            aria-pressed={controlRole === role}
                            onClick={() => {
                                setControlRole(role);
                                setPartyId('');
                            }}
                        >
                            {t(`aging.${role}`)}
                        </Button>
                    ))}
                </div>
                <div className="flex flex-col gap-1.5">
                    <span id="aging-as-of" className="text-sm font-medium">
                        {t('aging.asOf')}
                    </span>
                    <DatePicker
                        labelledBy="aging-as-of"
                        value={draftDate}
                        onChange={setDraftDate}
                    />
                </div>
                {canReadParties && (
                    <div className="w-56">
                        <SearchableSelect
                            aria-label={t('aging.party')}
                            placeholder={t('aging.allParties')}
                            emptyText={t('aging.noParty')}
                            clearable
                            items={(parties.data ?? [])
                                .filter((party) =>
                                    controlRole === 'receivables_control'
                                        ? party.is_customer
                                        : party.is_supplier,
                                )
                                .map((party) => ({
                                    value: party.id,
                                    label: party.name,
                                }))}
                            value={partyId}
                            onValueChange={setPartyId}
                        />
                    </div>
                )}
                <div
                    className="flex gap-1"
                    role="group"
                    aria-label={t('aging.basis')}
                >
                    {(['due_date', 'document_date'] as const).map((value) => (
                        <Button
                            key={value}
                            type="button"
                            variant={basis === value ? 'default' : 'outline'}
                            aria-pressed={basis === value}
                            onClick={() =>
                                setBasis(basis === value ? undefined : value)
                            }
                        >
                            {t(
                                value === 'due_date'
                                    ? 'aging.basisDueDate'
                                    : 'aging.basisDocumentDate',
                            )}
                        </Button>
                    ))}
                </div>
                <Button type="submit">{t('aging.show')}</Button>
            </form>

            {report.isError ? (
                <FormAlert>{genericErrorMessage(report.error, t)}</FormAlert>
            ) : data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : data.parties.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('aging.empty', { date: data.parameters.as_of })}
                </p>
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('aging.party')}</TableHead>
                                    {data.parameters.buckets.map((bucket) => (
                                        <TableHead
                                            key={bucket.key}
                                            className="text-end"
                                        >
                                            {bucketLabel(bucket)}
                                        </TableHead>
                                    ))}
                                    <TableHead className="text-end">
                                        {t('aging.total')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.parties.map((party) => (
                                    <Fragment key={party.party_id}>
                                        <TableRow className="bg-muted/40 font-medium">
                                            <TableCell>
                                                {party.party_name}
                                            </TableCell>
                                            {data.parameters.buckets.map(
                                                (bucket) => (
                                                    <TableCell
                                                        key={bucket.key}
                                                        className="text-end tabular-nums"
                                                    >
                                                        {money(
                                                            party.totals[
                                                                bucket.key
                                                            ] ?? '0',
                                                        )}
                                                    </TableCell>
                                                ),
                                            )}
                                            <TableCell className="text-end tabular-nums">
                                                {money(
                                                    party.totals.total ?? '0',
                                                )}
                                            </TableCell>
                                        </TableRow>
                                        {party.items.map((item) => (
                                            <TableRow key={item.open_item_id}>
                                                <TableCell className="ps-6 text-sm">
                                                    {canReadOpenItems ? (
                                                        <Link
                                                            to="/w/$companyId/allocations"
                                                            params={{
                                                                companyId,
                                                            }}
                                                            search={{
                                                                open_item:
                                                                    item.open_item_id,
                                                            }}
                                                            className="underline underline-offset-2"
                                                        >
                                                            {
                                                                item.document_number
                                                            }
                                                        </Link>
                                                    ) : (
                                                        item.document_number
                                                    )}
                                                    <span className="ms-2 text-xs text-muted-foreground">
                                                        {t('aging.itemDetail', {
                                                            due: item.due_date,
                                                            age: item.age_days,
                                                            currency:
                                                                item.currency,
                                                            amount: money(
                                                                item.remaining_amount,
                                                            ),
                                                        })}
                                                    </span>
                                                </TableCell>
                                                {data.parameters.buckets.map(
                                                    (bucket) => (
                                                        <TableCell
                                                            key={bucket.key}
                                                            className="text-end text-sm tabular-nums"
                                                        >
                                                            {item.bucket ===
                                                            bucket.key
                                                                ? money(
                                                                      item.remaining_functional,
                                                                  )
                                                                : ''}
                                                        </TableCell>
                                                    ),
                                                )}
                                                <TableCell className="text-end text-sm tabular-nums">
                                                    {money(
                                                        item.remaining_functional,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </Fragment>
                                ))}
                                <TableRow className="font-semibold">
                                    <TableCell>
                                        {t('aging.grandTotal', {
                                            currency: data.functional_currency,
                                        })}
                                    </TableCell>
                                    {data.parameters.buckets.map((bucket) => (
                                        <TableCell
                                            key={bucket.key}
                                            className="text-end tabular-nums"
                                        >
                                            {money(
                                                data.totals[bucket.key] ?? '0',
                                            )}
                                        </TableCell>
                                    ))}
                                    <TableCell className="text-end tabular-nums">
                                        {money(data.totals.total ?? '0')}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    {data.currency_totals.length > 0 && (
                        <section className="flex flex-col gap-2">
                            <h2 className="text-sm font-medium">
                                {t('aging.byCurrency')}
                            </h2>
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t('aging.currency')}
                                            </TableHead>
                                            {data.parameters.buckets.map(
                                                (bucket) => (
                                                    <TableHead
                                                        key={bucket.key}
                                                        className="text-end"
                                                    >
                                                        {bucketLabel(bucket)}
                                                    </TableHead>
                                                ),
                                            )}
                                            <TableHead className="text-end">
                                                {t('aging.total')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {data.currency_totals.map((row) => (
                                            <TableRow key={row.currency}>
                                                <TableCell className="font-medium">
                                                    {row.currency}
                                                </TableCell>
                                                {data.parameters.buckets.map(
                                                    (bucket) => (
                                                        <TableCell
                                                            key={bucket.key}
                                                            className="text-end tabular-nums"
                                                        >
                                                            {money(
                                                                row.buckets[
                                                                    bucket.key
                                                                ] ?? '0',
                                                            )}
                                                        </TableCell>
                                                    ),
                                                )}
                                                <TableCell className="text-end tabular-nums">
                                                    {money(row.total)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </section>
                    )}
                </div>
            )}

            {canManagePolicy && (
                <AgingPolicyDialog
                    companyId={companyId}
                    open={editingPolicy}
                    onOpenChange={setEditingPolicy}
                />
            )}
        </>
    );
}
