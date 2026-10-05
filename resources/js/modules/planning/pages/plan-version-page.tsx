import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import {
    CopyIcon,
    DownloadIcon,
    PlusIcon,
    SaveIcon,
    UploadIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DimensionTags, dimensionTags } from '@/components/dimension-tags';
import { FormAlert } from '@/components/form-alert';
import { MoneyInput } from '@/components/money-input';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatDecimal } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    allAccountsQuery,
    type Account,
} from '@/modules/general-ledger/accounts';
import { periodsQuery, type Period } from '@/modules/general-ledger/periods';
import { CopyLinesDialog } from '@/modules/planning/components/copy-lines-dialog';
import { LinesImportDialog } from '@/modules/planning/components/lines-import-dialog';
import { PublishDialog } from '@/modules/planning/components/publish-dialog';
import {
    VersionDialog,
    type VersionDialogMode,
} from '@/modules/planning/components/version-dialog';
import {
    cellKey,
    changesOf,
    cleanDimensions,
    gridOf,
    rowKey,
    sameAmount,
    sumAmounts,
    trimAmount,
    type GridRow,
} from '@/modules/planning/plan-grid';
import {
    deleteLine,
    enterCells,
    exportLines,
    linesQuery,
    planQuery,
    plansQuery,
    versionQuery,
    versionsQuery,
    type PlanLine,
    type PlanVersion,
} from '@/modules/planning/plans';
import {
    lineDimensionsQuery,
    type LineDimensionType,
} from '@/modules/transaction-entry/entries';

/** Accounts a plan can hold: active leaves of income and expense (PL-FR-02). */
const plannable = (account: Account) =>
    account.is_leaf &&
    account.status === 'active' &&
    (account.class === 'income' || account.class === 'expense');

/**
 * One version of a plan as a grid: a row per account and set of dimension values, a column per period of
 * the fiscal year (PL-FR-02). A draft is edited in place and saved together; a published or archived
 * version only shows its values (PL-FR-01). Lines also come in from CSV or a copy, and go out as CSV (PL-FR-03).
 */
export function PlanVersionPage({
    companyId,
    versionId,
    canManage,
    canPublish,
}: {
    companyId: string;
    versionId: string;
    canManage: boolean;
    canPublish: boolean;
}) {
    const { t } = useTranslation();
    const version = useQuery(versionQuery(companyId, versionId));
    const plan = useQuery({
        ...planQuery(companyId, version.data?.plan_id ?? ''),
        enabled: version.data !== undefined,
    });
    const periods = useQuery({
        ...periodsQuery(companyId, plan.data?.fiscal_year_id ?? ''),
        enabled: plan.data !== undefined,
    });
    const lines = useQuery(linesQuery(companyId, versionId));
    const accounts = useQuery(allAccountsQuery(companyId));
    const dimensions = useQuery(lineDimensionsQuery(companyId));
    const versions = useQuery(versionsQuery(companyId));
    const plans = useQuery(plansQuery(companyId));

    useDocumentTitle(version.data?.name ?? t('plans.pageTitle'));

    const failure =
        version.error ??
        plan.error ??
        periods.error ??
        lines.error ??
        accounts.error;

    if (failure) {
        return <FormAlert>{genericErrorMessage(failure, t)}</FormAlert>;
    }

    if (
        version.data === undefined ||
        plan.data === undefined ||
        periods.data === undefined ||
        lines.data === undefined ||
        accounts.data === undefined
    ) {
        return <Skeleton className="h-96 w-full" />;
    }

    // Lines can be copied from any other version of the same fiscal year, whichever plan it belongs to.
    const yearPlans = new Map(
        (plans.data ?? [])
            .filter(
                (other) => other.fiscal_year_id === plan.data.fiscal_year_id,
            )
            .map((other) => [other.id, other.name]),
    );
    const siblings = (versions.data ?? [])
        .filter(
            (other) => other.id !== versionId && yearPlans.has(other.plan_id),
        )
        .map((other) => ({
            id: other.id,
            label: `${yearPlans.get(other.plan_id)} · ${other.name}`,
        }));

    return (
        <GridEditor
            key={versionId}
            companyId={companyId}
            version={version.data}
            planName={plan.data.name}
            periods={periods.data}
            lines={lines.data}
            accounts={accounts.data}
            dimensionTypes={dimensions.data ?? []}
            siblings={siblings}
            canManage={canManage}
            canPublish={canPublish}
        />
    );
}

function GridEditor({
    companyId,
    version,
    planName,
    periods,
    lines,
    accounts,
    dimensionTypes,
    siblings,
    canManage,
    canPublish,
}: {
    companyId: string;
    version: PlanVersion;
    planName: string;
    periods: Period[];
    lines: PlanLine[];
    accounts: Account[];
    dimensionTypes: LineDimensionType[];
    siblings: { id: string; label: string }[];
    canManage: boolean;
    canPublish: boolean;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const editable = version.status === 'draft' && canManage;
    const periodIds = periods.map((period) => period.id);
    const accountOf = useMemo(
        () => new Map(accounts.map((account) => [account.id, account])),
        [accounts],
    );
    const names = useMemo(
        () => ({
            types: Object.fromEntries(
                dimensionTypes.map((type) => [type.key, type.label]),
            ),
            values: Object.fromEntries(
                dimensionTypes.flatMap((type) =>
                    type.values.map((value) => [
                        value.id,
                        { code: value.code, name: value.name },
                    ]),
                ),
            ),
        }),
        [dimensionTypes],
    );

    const saved = useMemo(() => gridOf(lines), [lines]);
    const [added, setAdded] = useState<GridRow[]>([]);
    const [draft, setDraft] = useState<Map<string, string>>(new Map());
    const [problems, setProblems] = useState<string[]>([]);
    const [formError, setFormError] = useState<string | null>(null);
    const [importing, setImporting] = useState(false);
    const [copying, setCopying] = useState(false);
    const [publishing, setPublishing] = useState<PlanVersion | null>(null);
    const [revising, setRevising] = useState<VersionDialogMode | null>(null);
    const [exporting, setExporting] = useState(false);

    // Rows with saved lines come first in their stored order; rows added here follow until they have lines.
    const rows = useMemo(() => {
        const known = new Set(saved.rows.map((row) => row.key));

        return [...saved.rows, ...added.filter((row) => !known.has(row.key))];
    }, [saved.rows, added]);

    const changes = useMemo(
        () => changesOf(rows, periodIds, saved.amounts, saved.lineIds, draft),
        [rows, periodIds, saved.amounts, saved.lineIds, draft],
    );
    const pending = changes.cells.length + changes.removed.length;

    const valueOf = (key: string): string =>
        draft.has(key)
            ? (draft.get(key) ?? '')
            : trimAmount(saved.amounts.get(key) ?? '');

    const nameOfRow = (row: GridRow) => {
        const account = accountOf.get(row.account_id);

        return account ? `${account.code} · ${account.name}` : row.account_id;
    };

    const save = useMutation({
        mutationFn: async () => {
            if (changes.cells.length > 0) {
                await enterCells(companyId, version.id, changes.cells);
            }

            for (const lineId of changes.removed) {
                await deleteLine(companyId, version.id, lineId);
            }
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'plans'],
            });
            setDraft(new Map());
            setProblems([]);
            toast.success(t('plans.grid.saved'));
        },
        onError: async (error) => {
            if (isApiError(error) && error.status === 422) {
                const found = Object.entries(error.errors).flatMap(
                    ([key, messages]) => {
                        const index = key.match(/^lines\.(\d+)/)?.[1];
                        const cell =
                            index === undefined
                                ? undefined
                                : changes.cells[Number(index)];
                        const where = cell
                            ? `${nameOfRow({
                                  key: '',
                                  account_id: cell.account_id,
                                  dimensions: cell.dimensions,
                              })}, ${periods.find((p) => p.id === cell.period_id)?.name ?? ''}`
                            : '';

                        return messages.map((message) =>
                            where ? `${where}: ${message}` : message,
                        );
                    },
                );

                if (found.length > 0) {
                    setProblems(found);
                    setFormError(t('plans.grid.rejected'));

                    return;
                }
            }

            // A draft that was published meanwhile, or any other refusal, shows the server's words.
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'plans'],
            });
            setFormError(actionErrorMessage(error, t));
        },
    });

    const totalOf = (periodId: string) =>
        sumAmounts(rows.map((row) => valueOf(cellKey(row.key, periodId))));
    const rowTotal = (row: GridRow) =>
        sumAmounts(
            periodIds.map((periodId) => valueOf(cellKey(row.key, periodId))),
        );
    const grandTotal = sumAmounts(
        rows.flatMap((row) =>
            periodIds.map((periodId) => valueOf(cellKey(row.key, periodId))),
        ),
    );
    const show = (amount: string) => formatDecimal(amount, i18n.language);

    const status = version.status;

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {planName} · {version.name}
                        </h1>
                        <Badge
                            variant={status === 'draft' ? 'outline' : 'default'}
                        >
                            {t(`plans.status.${status}`, status)}
                        </Badge>
                    </div>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {editable
                            ? t('plans.grid.descriptionDraft')
                            : t('plans.grid.descriptionReadOnly')}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        disabled={exporting}
                        onClick={async () => {
                            setExporting(true);

                            try {
                                await exportLines(companyId, version.id);
                            } catch {
                                toast.error(t('plans.grid.exportFailed'));
                            } finally {
                                setExporting(false);
                            }
                        }}
                    >
                        <DownloadIcon data-icon="inline-start" />
                        {t('plans.grid.export')}
                    </Button>
                    {editable && (
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setImporting(true)}
                            >
                                <UploadIcon data-icon="inline-start" />
                                {t('plans.import.open')}
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setCopying(true)}
                            >
                                <CopyIcon data-icon="inline-start" />
                                {t('plans.copy.open')}
                            </Button>
                        </>
                    )}
                    {version.status === 'draft' && canPublish && (
                        <Button
                            variant="outline"
                            disabled={pending > 0}
                            onClick={() => setPublishing(version)}
                        >
                            {t('plans.versions.publish')}
                        </Button>
                    )}
                    {version.status === 'published' && canManage && (
                        <Button
                            onClick={() =>
                                setRevising({ kind: 'revise', version })
                            }
                        >
                            {t('plans.versions.revise')}
                        </Button>
                    )}
                </div>
            </div>

            {formError && (
                <FormAlert>
                    {formError}
                    {problems.length > 0 && (
                        <ul
                            className="mt-2 flex max-h-48 flex-col gap-0.5 overflow-y-auto text-sm"
                            aria-label={t('plans.grid.problems')}
                        >
                            {problems.slice(0, 20).map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                            {problems.length > 20 && (
                                <li>
                                    {t('plans.import.more', {
                                        count: problems.length - 20,
                                    })}
                                </li>
                            )}
                        </ul>
                    )}
                </FormAlert>
            )}

            {rows.length === 0 ? (
                <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                    {editable
                        ? t('plans.grid.emptyDraft')
                        : t('plans.grid.emptyReadOnly')}
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="sticky left-0 z-10 min-w-56 bg-background">
                                    {t('plans.grid.account')}
                                </TableHead>
                                {periods.map((period) => (
                                    <TableHead
                                        key={period.id}
                                        className="min-w-36 text-right"
                                    >
                                        {period.name}
                                    </TableHead>
                                ))}
                                <TableHead className="min-w-36 text-right">
                                    {t('plans.grid.total')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.key}>
                                    <TableCell className="sticky left-0 z-10 bg-background align-top">
                                        <div className="font-medium">
                                            {nameOfRow(row)}
                                        </div>
                                        <DimensionTags
                                            tags={dimensionTags(
                                                row.dimensions,
                                                names,
                                            )}
                                        />
                                    </TableCell>
                                    {periods.map((period) => {
                                        const key = cellKey(row.key, period.id);
                                        const value = valueOf(key);
                                        const dirty =
                                            draft.has(key) &&
                                            !sameAmount(
                                                value,
                                                saved.amounts.get(key),
                                            );

                                        return (
                                            <TableCell
                                                key={period.id}
                                                className="text-right tabular-nums"
                                            >
                                                {editable ? (
                                                    <MoneyInput
                                                        value={value}
                                                        maxDecimals={6}
                                                        aria-label={`${nameOfRow(row)}, ${period.name}`}
                                                        data-dirty={
                                                            dirty
                                                                ? true
                                                                : undefined
                                                        }
                                                        className={
                                                            dirty
                                                                ? 'border-primary'
                                                                : undefined
                                                        }
                                                        onChange={(next) => {
                                                            setDraft(
                                                                (current) =>
                                                                    new Map(
                                                                        current,
                                                                    ).set(
                                                                        key,
                                                                        next,
                                                                    ),
                                                            );
                                                        }}
                                                    />
                                                ) : value === '' ? (
                                                    <span className="text-muted-foreground">
                                                        –
                                                    </span>
                                                ) : (
                                                    show(value)
                                                )}
                                            </TableCell>
                                        );
                                    })}
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {show(rowTotal(row))}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell className="sticky left-0 z-10 bg-muted font-medium">
                                    {t('plans.grid.total')}
                                </TableCell>
                                {periods.map((period) => (
                                    <TableCell
                                        key={period.id}
                                        className="text-right tabular-nums"
                                    >
                                        {show(totalOf(period.id))}
                                    </TableCell>
                                ))}
                                <TableCell className="text-right tabular-nums">
                                    {show(grandTotal)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}

            {editable && (
                <>
                    <AddRow
                        accounts={accounts.filter(plannable)}
                        dimensionTypes={dimensionTypes}
                        existing={rows}
                        onAdd={(row) =>
                            setAdded((current) => [...current, row])
                        }
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            disabled={pending === 0 || save.isPending}
                            onClick={() => {
                                setFormError(null);
                                setProblems([]);
                                save.mutate();
                            }}
                        >
                            <SaveIcon data-icon="inline-start" />
                            {save.isPending
                                ? t('common.saving')
                                : t('plans.grid.save')}
                        </Button>
                        <Button
                            variant="ghost"
                            disabled={pending === 0 || save.isPending}
                            onClick={() => {
                                setDraft(new Map());
                                setFormError(null);
                                setProblems([]);
                            }}
                        >
                            {t('plans.grid.discard')}
                        </Button>
                        <span
                            className="text-sm text-muted-foreground"
                            aria-live="polite"
                        >
                            {pending === 0
                                ? t('plans.grid.noChanges')
                                : t('plans.grid.pending', { count: pending })}
                        </span>
                    </div>
                </>
            )}

            <p className="text-sm">
                <Link
                    to="/w/$companyId/plans"
                    params={{ companyId }}
                    className="text-muted-foreground underline-offset-4 hover:underline"
                >
                    {t('plans.grid.back')}
                </Link>
            </p>

            <LinesImportDialog
                companyId={companyId}
                versionId={version.id}
                open={importing}
                onOpenChange={setImporting}
            />
            <CopyLinesDialog
                companyId={companyId}
                version={version}
                siblings={siblings}
                periods={periods}
                open={copying}
                onOpenChange={setCopying}
            />
            <PublishDialog
                companyId={companyId}
                version={publishing}
                onOpenChange={(open) => !open && setPublishing(null)}
            />
            <VersionDialog
                companyId={companyId}
                mode={revising}
                open={revising !== null}
                onOpenChange={(open) => !open && setRevising(null)}
            />
        </>
    );
}

/** Adds an empty row for an account and dimension values; its cells are filled in the grid. */
function AddRow({
    accounts,
    dimensionTypes,
    existing,
    onAdd,
}: {
    accounts: Account[];
    dimensionTypes: LineDimensionType[];
    existing: GridRow[];
    onAdd: (row: GridRow) => void;
}) {
    const { t } = useTranslation();
    const [accountId, setAccountId] = useState('');
    const [chosen, setChosen] = useState<Record<string, string>>({});
    const [error, setError] = useState<string | null>(null);

    return (
        <form
            noValidate
            className="flex flex-col gap-3 rounded-lg border p-4"
            aria-label={t('plans.grid.addRow')}
            onSubmit={(event) => {
                event.preventDefault();

                if (accountId === '') {
                    setError(t('plans.grid.chooseAccount'));

                    return;
                }

                const dimensions = cleanDimensions(chosen);
                const key = rowKey(accountId, dimensions);

                if (existing.some((row) => row.key === key)) {
                    setError(t('plans.grid.rowExists'));

                    return;
                }

                setError(null);
                onAdd({ key, account_id: accountId, dimensions });
                setAccountId('');
                setChosen({});
            }}
        >
            <h2 className="text-sm font-medium">{t('plans.grid.addRow')}</h2>
            {error && <FormAlert>{error}</FormAlert>}
            <div className="flex flex-wrap items-end gap-2">
                <SearchableSelect
                    items={accounts.map((account) => ({
                        value: account.id,
                        label: `${account.code} · ${account.name}`,
                    }))}
                    value={accountId}
                    onValueChange={setAccountId}
                    placeholder={t('plans.grid.account')}
                    aria-label={t('plans.grid.account')}
                    emptyText={t('common.noMatch')}
                    className="w-72"
                />
                {dimensionTypes.map((type) => (
                    <SearchableSelect
                        key={type.key}
                        items={type.values.map((value) => ({
                            value: value.id,
                            label: `${value.code} · ${value.name}`,
                        }))}
                        value={chosen[type.key] ?? ''}
                        onValueChange={(value) =>
                            setChosen((current) => ({
                                ...current,
                                [type.key]: value,
                            }))
                        }
                        placeholder={type.label}
                        aria-label={type.label}
                        emptyText={t('common.noMatch')}
                        clearable
                        className="w-52"
                    />
                ))}
                <Button type="submit" variant="outline">
                    <PlusIcon data-icon="inline-start" />
                    {t('plans.grid.add')}
                </Button>
            </div>
        </form>
    );
}
