import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, TrendingUpIcon } from 'lucide-react';
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
import { useLastDefined } from '@/hooks/use-last-defined';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { RateTypeDialog } from '@/modules/master-data/components/rate-type-dialog';
import {
    rateTypesQuery,
    setRateTypeStatus,
    type RateType,
} from '@/modules/master-data/exchange-rates';

/**
 * Rate types of the company (MD-FR-07), such as a central bank mid rate or a tax rate, each with an
 * optional age limit for the rates it holds (MD-FR-08). A deactivated type takes no new rates; the
 * rates it already holds stay readable.
 */
export function RateTypesPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<RateType | null>(null);
    const [deactivating, setDeactivating] = useState<RateType | null>(null);
    const asking = useLastDefined(deactivating);

    useDocumentTitle(t('rates.types.pageTitle'));

    const types = useQuery(rateTypesQuery(companyId));

    const toggle = useMutation({
        mutationFn: (type: RateType) =>
            setRateTypeStatus(
                companyId,
                type.id,
                type.status === 'active' ? 'deactivate' : 'reactivate',
            ),
        onSuccess: async (type) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'rate-types'],
            });
            toast.success(
                t(
                    type.status === 'active'
                        ? 'rates.type.reactivated'
                        : 'rates.type.deactivated',
                    { label: type.label },
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
                        {t('rates.types.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('rates.types.description')}
                    </p>
                </div>
                {canManage && (
                    <Button onClick={() => setCreating(true)}>
                        <PlusIcon data-icon="inline-start" />
                        {t('rates.types.add')}
                    </Button>
                )}
            </div>

            {types.isError ? (
                <FormAlert>{genericErrorMessage(types.error, t)}</FormAlert>
            ) : types.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : types.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <TrendingUpIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('rates.types.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('rates.types.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && (
                        <EmptyContent>
                            <Button onClick={() => setCreating(true)}>
                                <PlusIcon data-icon="inline-start" />
                                {t('rates.types.add')}
                            </Button>
                        </EmptyContent>
                    )}
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('rates.type.label')}</TableHead>
                                <TableHead>{t('rates.type.key')}</TableHead>
                                <TableHead className="text-end">
                                    {t('rates.type.maxAge')}
                                </TableHead>
                                <TableHead>{t('rates.types.status')}</TableHead>
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
                            {types.data.map((type) => (
                                <TableRow
                                    key={type.id}
                                    className={
                                        type.status === 'active'
                                            ? undefined
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <TableCell className="font-medium">
                                        {type.label}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {type.key}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {type.max_age_days === null
                                            ? t('rates.types.noLimit')
                                            : t('rates.types.days', {
                                                  count: type.max_age_days,
                                              })}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                type.status === 'active'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {t(
                                                type.status === 'active'
                                                    ? 'rates.types.active'
                                                    : 'rates.types.inactive',
                                            )}
                                        </Badge>
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-end">
                                            <RowActions label={type.label}>
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        setEditing(type)
                                                    }
                                                >
                                                    {t(
                                                        'rates.types.editAction',
                                                    )}
                                                </DropdownMenuItem>
                                                {type.status === 'active' ? (
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() =>
                                                            setDeactivating(
                                                                type,
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'rates.types.deactivateAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                ) : (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            toggle.mutate(type)
                                                        }
                                                    >
                                                        {t(
                                                            'rates.types.reactivateAction',
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

            <RateTypeDialog
                companyId={companyId}
                open={creating}
                onOpenChange={setCreating}
            />
            <RateTypeDialog
                companyId={companyId}
                type={editing ?? undefined}
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
                title={t('confirm.deactivateRateType.title', {
                    label: asking?.label ?? '',
                })}
                description={t('confirm.deactivateRateType.description')}
                confirmLabel={t('confirm.deactivateRateType.action')}
                pending={toggle.isPending}
                destructive
                onConfirm={() => deactivating && toggle.mutate(deactivating)}
            />
        </>
    );
}
