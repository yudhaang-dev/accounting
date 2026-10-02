import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CoinsIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
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
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { CurrencyDialog } from '@/modules/master-data/components/currency-dialog';
import {
    currenciesQuery,
    setCurrencyStatus,
    type Currency,
} from '@/modules/master-data/currencies';

/**
 * Currencies of the company (MD-FR-06): the ones it activated from ISO 4217, with the scale and
 * rounding mode it uses for each. The functional currency is always active and cannot be deactivated.
 */
export function CurrenciesPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [activating, setActivating] = useState(false);
    const [editing, setEditing] = useState<Currency | null>(null);
    const [deactivating, setDeactivating] = useState<Currency | null>(null);
    const asking = useLastDefined(deactivating);

    useDocumentTitle(t('currencies.pageTitle'));

    const currencies = useQuery(currenciesQuery(companyId));
    const taken = new Set((currencies.data ?? []).map((c) => c.code));

    const toggle = useMutation({
        mutationFn: (currency: Currency) =>
            setCurrencyStatus(
                companyId,
                currency.code,
                currency.status === 'active' ? 'deactivate' : 'reactivate',
            ),
        onSuccess: async (currency) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'currencies'],
            });
            toast.success(
                t(
                    currency.status === 'active'
                        ? 'currencies.reactivated'
                        : 'currencies.deactivated',
                    { code: currency.code },
                ),
            );
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setDeactivating(null),
    });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('currencies.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('currencies.description')}
                    </p>
                </div>
                {canManage && (
                    <Button onClick={() => setActivating(true)}>
                        <PlusIcon data-icon="inline-start" />
                        {t('currencies.activate')}
                    </Button>
                )}
            </div>

            {currencies.isError ? (
                <FormAlert>
                    {genericErrorMessage(currencies.error, t)}
                </FormAlert>
            ) : currencies.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : currencies.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <CoinsIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('currencies.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('currencies.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-24">
                                    {t('currencies.code')}
                                </TableHead>
                                <TableHead>{t('currencies.name')}</TableHead>
                                <TableHead className="text-end">
                                    {t('currencies.scale')}
                                </TableHead>
                                <TableHead>
                                    {t('currencies.rounding.title')}
                                </TableHead>
                                <TableHead>{t('currencies.status')}</TableHead>
                                {canManage && (
                                    <TableHead className="w-12">
                                        <span className="sr-only">
                                            {t('currencies.actions')}
                                        </span>
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {currencies.data.map((currency) => (
                                <TableRow
                                    key={currency.code}
                                    className={
                                        currency.status === 'active'
                                            ? undefined
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <TableCell className="font-mono text-xs">
                                        {currency.code}
                                    </TableCell>
                                    <TableCell>
                                        <span className="flex flex-wrap items-center gap-2">
                                            {currency.name}
                                            {currency.is_functional && (
                                                <Badge variant="secondary">
                                                    {t('currencies.functional')}
                                                </Badge>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {currency.scale}
                                    </TableCell>
                                    <TableCell>
                                        {t(
                                            `currencies.rounding.${currency.rounding_mode}`,
                                            {
                                                defaultValue:
                                                    currency.rounding_mode,
                                            },
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                currency.status === 'active'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {t(
                                                currency.status === 'active'
                                                    ? 'currencies.active'
                                                    : 'currencies.inactive',
                                            )}
                                        </Badge>
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-end">
                                            <RowActions label={currency.code}>
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        setEditing(currency)
                                                    }
                                                >
                                                    {t('currencies.editAction')}
                                                </DropdownMenuItem>
                                                {currency.status ===
                                                'active' ? (
                                                    !currency.is_functional && (
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onClick={() =>
                                                                setDeactivating(
                                                                    currency,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'currencies.deactivateAction',
                                                            )}
                                                        </DropdownMenuItem>
                                                    )
                                                ) : (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            toggle.mutate(
                                                                currency,
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'currencies.reactivateAction',
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
            )}

            <CurrencyDialog
                companyId={companyId}
                taken={taken}
                open={activating}
                onOpenChange={setActivating}
            />
            <CurrencyDialog
                companyId={companyId}
                taken={taken}
                currency={editing ?? undefined}
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
                title={t('confirm.deactivateCurrency.title', {
                    code: asking?.code ?? '',
                })}
                description={t('confirm.deactivateCurrency.description')}
                confirmLabel={t('confirm.deactivateCurrency.action')}
                pending={toggle.isPending}
                destructive
                onConfirm={() => deactivating && toggle.mutate(deactivating)}
            />
        </>
    );
}
