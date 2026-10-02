import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { PlusIcon, TrendingUpIcon, UploadIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Label } from '@/components/ui/label';
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
import { formatDecimal } from '@/lib/decimal';
import { genericErrorMessage } from '@/lib/errors';
import { usePageSize } from '@/lib/page-size';
import { ExchangeRateDialog } from '@/modules/master-data/components/exchange-rate-dialog';
import { RateImportDialog } from '@/modules/master-data/components/rate-import-dialog';
import { currenciesQuery } from '@/modules/master-data/currencies';
import {
    ratesQuery,
    rateTypesQuery,
    type ExchangeRate,
} from '@/modules/master-data/exchange-rates';

const ANY = '__any';

/**
 * Exchange rates (MD-FR-07): functional currency per 1 unit of a foreign currency, per rate type and
 * effective date. Only the current version of each rate is listed unless asked otherwise; a correction
 * adds a version and never overwrites. Rates are recorded one by one or imported from CSV.
 */
export function ExchangeRatesPage({
    companyId,
    canManage,
    canReadTypes,
}: {
    companyId: string;
    canManage: boolean;
    /** Whether the user may open the rate types screen, which the empty state points to. */
    canReadTypes: boolean;
}) {
    const { t, i18n } = useTranslation();
    const [rateType, setRateType] = useState<string | undefined>();
    const [currency, setCurrency] = useState<string | undefined>();
    const [includeSuperseded, setIncludeSuperseded] = useState(false);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('exchange-rates');
    const [recording, setRecording] = useState(false);
    const [correcting, setCorrecting] = useState<ExchangeRate | null>(null);
    const [importing, setImporting] = useState(false);

    useDocumentTitle(t('rates.pageTitle'));

    const types = useQuery(rateTypesQuery(companyId));
    const currencies = useQuery(currenciesQuery(companyId));
    const rates = useQuery(
        ratesQuery(companyId, {
            rateType,
            currency,
            includeSuperseded,
            page,
            perPage: pageSize,
        }),
    );
    const labelOf = new Map(
        (types.data ?? []).map((type) => [type.key, type.label]),
    );

    const typeItems = [
        { value: ANY, label: t('rates.allTypes') },
        ...(types.data ?? []).map((type) => ({
            value: type.key,
            label: type.label,
        })),
    ];
    const currencyItems = [
        { value: ANY, label: t('rates.allCurrencies') },
        ...(currencies.data ?? [])
            .filter((c) => !c.is_functional)
            .map((c) => ({ value: c.code, label: c.code })),
    ];
    const noTypes = types.data !== undefined && types.data.length === 0;
    const functional = currencies.data?.find((c) => c.is_functional)?.code;

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('rates.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {functional
                            ? t('rates.descriptionWith', {
                                  currency: functional,
                              })
                            : t('rates.description')}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {canReadTypes && (
                        <Button
                            variant="outline"
                            render={
                                <Link
                                    to="/w/$companyId/exchange-rates/types"
                                    params={{ companyId }}
                                />
                            }
                        >
                            {t('rates.manageTypes')}
                        </Button>
                    )}
                    {canManage && (
                        <>
                            <Button
                                variant="outline"
                                disabled={noTypes}
                                onClick={() => setImporting(true)}
                            >
                                <UploadIcon data-icon="inline-start" />
                                {t('rates.import.open')}
                            </Button>
                            <Button
                                disabled={noTypes}
                                onClick={() => setRecording(true)}
                            >
                                <PlusIcon data-icon="inline-start" />
                                {t('rates.record')}
                            </Button>
                        </>
                    )}
                </div>
            </div>

            {noTypes ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <TrendingUpIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('rates.noTypes')}</EmptyTitle>
                        <EmptyDescription>
                            {t('rates.noTypesDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canReadTypes && (
                        <Button
                            render={
                                <Link
                                    to="/w/$companyId/exchange-rates/types"
                                    params={{ companyId }}
                                />
                            }
                        >
                            {t('rates.manageTypes')}
                        </Button>
                    )}
                </Empty>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-3">
                        <Select
                            value={rateType ?? ANY}
                            items={typeItems}
                            onValueChange={(value) => {
                                setRateType(
                                    value === ANY || value === null
                                        ? undefined
                                        : value,
                                );
                                setPage(1);
                            }}
                        >
                            <SelectTrigger
                                className="w-52"
                                aria-label={t('rates.rateType')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    {typeItems.map((item) => (
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
                        <Select
                            value={currency ?? ANY}
                            items={currencyItems}
                            onValueChange={(value) => {
                                setCurrency(
                                    value === ANY || value === null
                                        ? undefined
                                        : value,
                                );
                                setPage(1);
                            }}
                        >
                            <SelectTrigger
                                className="w-44"
                                aria-label={t('rates.currency')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    {currencyItems.map((item) => (
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
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="rates-superseded"
                                checked={includeSuperseded}
                                onCheckedChange={(checked) => {
                                    setIncludeSuperseded(checked === true);
                                    setPage(1);
                                }}
                            />
                            <Label
                                htmlFor="rates-superseded"
                                className="font-normal"
                            >
                                {t('rates.showHistory')}
                            </Label>
                        </div>
                    </div>

                    {rates.isError ? (
                        <FormAlert>
                            {genericErrorMessage(rates.error, t)}
                        </FormAlert>
                    ) : rates.data === undefined ? (
                        <Skeleton className="h-48 w-full" />
                    ) : rates.data.total === 0 ? (
                        <Empty className="max-w-xl flex-none border py-10">
                            <EmptyHeader>
                                <EmptyMedia variant="icon">
                                    <TrendingUpIcon />
                                </EmptyMedia>
                                <EmptyTitle>{t('rates.empty')}</EmptyTitle>
                                <EmptyDescription>
                                    {t('rates.emptyDescription')}
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
                                                {t('rates.effectiveDate')}
                                            </TableHead>
                                            <TableHead>
                                                {t('rates.rateType')}
                                            </TableHead>
                                            <TableHead>
                                                {t('rates.currency')}
                                            </TableHead>
                                            <TableHead className="text-end">
                                                {t('rates.rate')}
                                            </TableHead>
                                            <TableHead>
                                                {t('rates.version')}
                                            </TableHead>
                                            <TableHead>
                                                {t('rates.source')}
                                            </TableHead>
                                            {canManage && (
                                                <TableHead className="w-12">
                                                    <span className="sr-only">
                                                        {t('rates.actions')}
                                                    </span>
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rates.data.rates.map((rate) => (
                                            <TableRow key={rate.id}>
                                                <TableCell className="tabular-nums">
                                                    {rate.effective_date}
                                                </TableCell>
                                                <TableCell>
                                                    {labelOf.get(
                                                        rate.rate_type,
                                                    ) ?? rate.rate_type}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {rate.currency}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums">
                                                    {formatDecimal(
                                                        rate.rate,
                                                        i18n.language,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            rate.version > 1
                                                                ? 'secondary'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {t('rates.versionNo', {
                                                            version:
                                                                rate.version,
                                                        })}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {t(
                                                        `rates.sources.${rate.source}`,
                                                        {
                                                            defaultValue:
                                                                rate.source,
                                                        },
                                                    )}
                                                </TableCell>
                                                {canManage && (
                                                    <TableCell className="text-end">
                                                        <RowActions
                                                            label={`${rate.currency} ${rate.effective_date}`}
                                                        >
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    setCorrecting(
                                                                        rate,
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'rates.correctAction',
                                                                )}
                                                            </DropdownMenuItem>
                                                        </RowActions>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            <TablePagination
                                total={rates.data.total}
                                page={rates.data.page}
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
            )}

            <ExchangeRateDialog
                companyId={companyId}
                open={recording}
                onOpenChange={setRecording}
            />
            <ExchangeRateDialog
                companyId={companyId}
                correcting={correcting ?? undefined}
                open={correcting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCorrecting(null);
                    }
                }}
            />
            <RateImportDialog
                companyId={companyId}
                open={importing}
                onOpenChange={setImporting}
            />
        </>
    );
}
