import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { TFunction } from 'i18next';
import { PlusIcon, SearchIcon, ShapesIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
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
import { ALL, usePageSize } from '@/lib/page-size';
import { cn } from '@/lib/utils';
import { DimensionTypeDialog } from '@/modules/master-data/components/dimension-type-dialog';
import { DimensionValueDialog } from '@/modules/master-data/components/dimension-value-dialog';
import {
    deleteDimensionValue,
    dimensionTypesQuery,
    dimensionValuesQuery,
    inTreeOrder,
    setDimensionTypeStatus,
    setDimensionValueStatus,
    valueDepths,
    type DimensionType,
    type DimensionValue,
} from '@/modules/master-data/dimensions';

type Pending =
    | { kind: 'deactivateType'; type: DimensionType }
    | { kind: 'deactivateValue'; value: DimensionValue }
    | { kind: 'deleteValue'; value: DimensionValue };

function validity(value: DimensionValue, t: TFunction): string {
    if (!value.valid_from && !value.valid_to) {
        return t('dimensions.value.always');
    }

    if (value.valid_from && value.valid_to) {
        return `${value.valid_from} – ${value.valid_to}`;
    }

    return value.valid_from
        ? t('dimensions.value.from', { date: value.valid_from })
        : t('dimensions.value.until', { date: value.valid_to ?? '' });
}

/**
 * Dimension types and their values (MD-FR-02, MD-FR-03): the types on the left, the tree of values of
 * the chosen type on the right, each with add, edit, deactivate and reactivate. A value that was never
 * used can also be deleted; the server says why when it cannot.
 */
export function DimensionsPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [chosenId, setChosenId] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [showInactive, setShowInactive] = useState(false);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = usePageSize('dimension-values', {
        fallback: 50,
        allowAll: true,
    });
    const [creatingType, setCreatingType] = useState(false);
    const [editingType, setEditingType] = useState<DimensionType | null>(null);
    const [creatingValue, setCreatingValue] = useState(false);
    const [editingValue, setEditingValue] = useState<DimensionValue | null>(
        null,
    );
    const [pending, setPending] = useState<Pending | null>(null);
    const asking = useLastDefined(pending);

    useDocumentTitle(t('dimensions.pageTitle'));

    const types = useQuery(dimensionTypesQuery(companyId));
    const chosen =
        types.data?.find((type) => type.id === chosenId) ?? types.data?.[0];
    const values = useQuery({
        ...dimensionValuesQuery(companyId, chosen?.id ?? ''),
        enabled: chosen !== undefined,
    });

    // The tree is indented by depth in the whole set, so a filtered view keeps every value at its level.
    const depths = useMemo(() => valueDepths(values.data ?? []), [values.data]);
    const ordered = useMemo(
        () => inTreeOrder(values.data ?? []),
        [values.data],
    );
    const needle = search.trim().toLowerCase();
    const matching = ordered.filter(
        (value) =>
            (showInactive || value.status === 'active') &&
            (!needle ||
                value.code.toLowerCase().startsWith(needle) ||
                value.name.toLowerCase().includes(needle)),
    );
    const lastPage =
        pageSize === ALL
            ? 1
            : Math.max(1, Math.ceil(matching.length / pageSize));
    const current = Math.min(page, lastPage);
    const shown =
        pageSize === ALL
            ? matching
            : matching.slice((current - 1) * pageSize, current * pageSize);

    const run = useMutation({
        mutationFn: async (
            action:
                | Pending
                | { kind: 'reactivateType'; type: DimensionType }
                | { kind: 'reactivateValue'; value: DimensionValue },
        ) => {
            switch (action.kind) {
                case 'deactivateType':
                    return setDimensionTypeStatus(
                        companyId,
                        action.type.id,
                        'deactivate',
                    );
                case 'reactivateType':
                    return setDimensionTypeStatus(
                        companyId,
                        action.type.id,
                        'reactivate',
                    );
                case 'deactivateValue':
                    return setDimensionValueStatus(
                        companyId,
                        action.value.id,
                        'deactivate',
                    );
                case 'reactivateValue':
                    return setDimensionValueStatus(
                        companyId,
                        action.value.id,
                        'reactivate',
                    );
                case 'deleteValue':
                    return deleteDimensionValue(companyId, action.value.id);
            }
        },
        onSuccess: async (_result, action) => {
            await Promise.all([
                queryClient.invalidateQueries({
                    queryKey: ['companies', companyId, 'dimension-types'],
                }),
                queryClient.invalidateQueries({
                    queryKey: ['companies', companyId, 'dimension-values'],
                }),
            ]);

            const subject =
                'type' in action ? action.type.label : action.value.code;

            toast.success(
                t(`dimensions.done.${action.kind}`, { name: subject }),
            );
        },
        // The server says exactly why (active children, value in use), already translated.
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setPending(null),
    });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('dimensions.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('dimensions.description')}
                    </p>
                </div>
                {canManage && (
                    <Button onClick={() => setCreatingType(true)}>
                        <PlusIcon data-icon="inline-start" />
                        {t('dimensions.addType')}
                    </Button>
                )}
            </div>

            {types.isError ? (
                <FormAlert>{genericErrorMessage(types.error, t)}</FormAlert>
            ) : types.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : types.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ShapesIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('dimensions.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('dimensions.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && (
                        <EmptyContent>
                            <Button onClick={() => setCreatingType(true)}>
                                <PlusIcon data-icon="inline-start" />
                                {t('dimensions.addType')}
                            </Button>
                        </EmptyContent>
                    )}
                </Empty>
            ) : (
                <div className="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <nav aria-label={t('dimensions.types')}>
                        <ul className="flex flex-col gap-1">
                            {types.data.map((type) => (
                                <li key={type.id}>
                                    <button
                                        type="button"
                                        aria-current={
                                            type.id === chosen?.id
                                                ? 'true'
                                                : undefined
                                        }
                                        className={cn(
                                            'flex w-full flex-col items-start gap-0.5 rounded-lg border px-3 py-2 text-left text-sm transition-colors hover:bg-muted',
                                            type.id === chosen?.id &&
                                                'border-primary bg-muted',
                                            type.status !== 'active' &&
                                                'text-muted-foreground',
                                        )}
                                        onClick={() => {
                                            setChosenId(type.id);
                                            setPage(1);
                                            setSearch('');
                                        }}
                                    >
                                        <span className="flex w-full items-center justify-between gap-2">
                                            <span className="font-medium">
                                                {type.label}
                                            </span>
                                            {type.status !== 'active' && (
                                                <Badge variant="outline">
                                                    {t('dimensions.inactive')}
                                                </Badge>
                                            )}
                                        </span>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {type.key}
                                            {type.is_hierarchical &&
                                                ` · ${t('dimensions.type.hierarchicalShort')}`}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </nav>

                    {chosen && (
                        <section
                            className="flex min-w-0 flex-col gap-3"
                            aria-label={chosen.label}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="flex flex-col gap-1">
                                    <h2 className="text-lg font-semibold tracking-tight">
                                        {chosen.label}
                                    </h2>
                                    <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                        <span className="font-mono">
                                            {chosen.key}
                                        </span>
                                        {chosen.is_hierarchical && (
                                            <Badge variant="secondary">
                                                {t(
                                                    'dimensions.type.hierarchicalShort',
                                                )}
                                            </Badge>
                                        )}
                                        {chosen.leaf_only && (
                                            <Badge variant="secondary">
                                                {t(
                                                    'dimensions.type.leafOnlyShort',
                                                )}
                                            </Badge>
                                        )}
                                        {chosen.status !== 'active' && (
                                            <Badge variant="outline">
                                                {t('dimensions.inactive')}
                                            </Badge>
                                        )}
                                    </p>
                                </div>
                                {canManage && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setEditingType(chosen)
                                            }
                                        >
                                            {t('dimensions.editType')}
                                        </Button>
                                        {chosen.status === 'active' ? (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setPending({
                                                        kind: 'deactivateType',
                                                        type: chosen,
                                                    })
                                                }
                                            >
                                                {t('dimensions.deactivateType')}
                                            </Button>
                                        ) : (
                                            <Button
                                                variant="outline"
                                                disabled={run.isPending}
                                                onClick={() =>
                                                    run.mutate({
                                                        kind: 'reactivateType',
                                                        type: chosen,
                                                    })
                                                }
                                            >
                                                {t('dimensions.reactivateType')}
                                            </Button>
                                        )}
                                        <Button
                                            disabled={
                                                chosen.status !== 'active'
                                            }
                                            onClick={() =>
                                                setCreatingValue(true)
                                            }
                                        >
                                            <PlusIcon data-icon="inline-start" />
                                            {t('dimensions.addValue')}
                                        </Button>
                                    </div>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <InputGroup className="w-full sm:w-72">
                                    <InputGroupAddon>
                                        <SearchIcon aria-hidden />
                                    </InputGroupAddon>
                                    <InputGroupInput
                                        type="search"
                                        value={search}
                                        placeholder={t('dimensions.search')}
                                        aria-label={t('dimensions.search')}
                                        onChange={(event) => {
                                            setSearch(event.target.value);
                                            setPage(1);
                                        }}
                                    />
                                </InputGroup>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="dimension-show-inactive"
                                        checked={showInactive}
                                        onCheckedChange={(checked) => {
                                            setShowInactive(checked === true);
                                            setPage(1);
                                        }}
                                    />
                                    <Label
                                        htmlFor="dimension-show-inactive"
                                        className="font-normal"
                                    >
                                        {t('dimensions.showInactive')}
                                    </Label>
                                </div>
                            </div>

                            {values.isError ? (
                                <FormAlert>
                                    {genericErrorMessage(values.error, t)}
                                </FormAlert>
                            ) : values.data === undefined ? (
                                <Skeleton className="h-48 w-full" />
                            ) : values.data.length === 0 ? (
                                <Empty className="flex-none border py-10">
                                    <EmptyHeader>
                                        <EmptyTitle>
                                            {t('dimensions.value.empty')}
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            {t(
                                                'dimensions.value.emptyDescription',
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
                                                    <TableHead className="w-32">
                                                        {t(
                                                            'dimensions.value.code',
                                                        )}
                                                    </TableHead>
                                                    <TableHead>
                                                        {t(
                                                            'dimensions.value.name',
                                                        )}
                                                    </TableHead>
                                                    <TableHead>
                                                        {t(
                                                            'dimensions.value.validity',
                                                        )}
                                                    </TableHead>
                                                    <TableHead>
                                                        {t('dimensions.status')}
                                                    </TableHead>
                                                    {canManage && (
                                                        <TableHead className="w-12">
                                                            <span className="sr-only">
                                                                {t(
                                                                    'dimensions.actions',
                                                                )}
                                                            </span>
                                                        </TableHead>
                                                    )}
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {shown.length === 0 && (
                                                    <TableRow>
                                                        <TableCell
                                                            colSpan={
                                                                canManage
                                                                    ? 5
                                                                    : 4
                                                            }
                                                            className="py-8 text-center text-muted-foreground"
                                                        >
                                                            {t(
                                                                'dimensions.value.noMatch',
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                                {shown.map((value) => (
                                                    <TableRow
                                                        key={value.id}
                                                        className={
                                                            value.status ===
                                                            'active'
                                                                ? undefined
                                                                : 'text-muted-foreground'
                                                        }
                                                    >
                                                        <TableCell className="font-mono text-xs">
                                                            {value.code}
                                                        </TableCell>
                                                        <TableCell>
                                                            <span
                                                                className={
                                                                    value.is_leaf
                                                                        ? undefined
                                                                        : 'font-medium'
                                                                }
                                                                style={{
                                                                    paddingInlineStart: `${(depths.get(value.id) ?? 0) * 20}px`,
                                                                }}
                                                            >
                                                                {value.name}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {validity(value, t)}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant={
                                                                    value.status ===
                                                                    'active'
                                                                        ? 'secondary'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                {t(
                                                                    value.status ===
                                                                        'active'
                                                                        ? 'dimensions.active'
                                                                        : 'dimensions.inactive',
                                                                )}
                                                            </Badge>
                                                        </TableCell>
                                                        {canManage && (
                                                            <TableCell className="text-end">
                                                                <RowActions
                                                                    label={
                                                                        value.code
                                                                    }
                                                                >
                                                                    <DropdownMenuItem
                                                                        onClick={() =>
                                                                            setEditingValue(
                                                                                value,
                                                                            )
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'dimensions.editAction',
                                                                        )}
                                                                    </DropdownMenuItem>
                                                                    {value.status ===
                                                                    'active' ? (
                                                                        <DropdownMenuItem
                                                                            onClick={() =>
                                                                                setPending(
                                                                                    {
                                                                                        kind: 'deactivateValue',
                                                                                        value,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            {t(
                                                                                'dimensions.deactivateAction',
                                                                            )}
                                                                        </DropdownMenuItem>
                                                                    ) : (
                                                                        <DropdownMenuItem
                                                                            onClick={() =>
                                                                                run.mutate(
                                                                                    {
                                                                                        kind: 'reactivateValue',
                                                                                        value,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            {t(
                                                                                'dimensions.reactivateAction',
                                                                            )}
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    <DropdownMenuItem
                                                                        variant="destructive"
                                                                        onClick={() =>
                                                                            setPending(
                                                                                {
                                                                                    kind: 'deleteValue',
                                                                                    value,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'dimensions.deleteAction',
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
                                        total={matching.length}
                                        page={current}
                                        pageSize={pageSize}
                                        allowAll
                                        onPageChange={setPage}
                                        onPageSizeChange={(size) => {
                                            setPageSize(size);
                                            setPage(1);
                                        }}
                                    />
                                </div>
                            )}
                        </section>
                    )}
                </div>
            )}

            <DimensionTypeDialog
                companyId={companyId}
                open={creatingType}
                onOpenChange={setCreatingType}
                onSaved={(type) => setChosenId(type.id)}
            />
            <DimensionTypeDialog
                companyId={companyId}
                type={editingType ?? undefined}
                open={editingType !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditingType(null);
                    }
                }}
            />
            {chosen && (
                <>
                    <DimensionValueDialog
                        companyId={companyId}
                        type={chosen}
                        open={creatingValue}
                        onOpenChange={setCreatingValue}
                    />
                    <DimensionValueDialog
                        companyId={companyId}
                        type={chosen}
                        value={editingValue ?? undefined}
                        open={editingValue !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setEditingValue(null);
                            }
                        }}
                    />
                </>
            )}
            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPending(null);
                    }
                }}
                title={t(`confirm.${asking?.kind ?? 'deactivateValue'}.title`, {
                    name:
                        asking === undefined
                            ? ''
                            : 'type' in asking
                              ? asking.type.label
                              : asking.value.code,
                })}
                description={t(
                    `confirm.${asking?.kind ?? 'deactivateValue'}.description`,
                )}
                confirmLabel={t(
                    `confirm.${asking?.kind ?? 'deactivateValue'}.action`,
                )}
                pending={run.isPending}
                destructive
                onConfirm={() => pending && run.mutate(pending)}
            />
        </>
    );
}
