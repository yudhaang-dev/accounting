import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { LayersIcon, PlusIcon, SparklesIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
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
import { genericErrorMessage } from '@/lib/errors';
import { NumberingDialog } from '@/modules/transaction-entry/components/numbering-dialog';
import { TemplateImportDialog } from '@/modules/transaction-entry/components/template-import-dialog';
import { TransactionTypeForm } from '@/modules/transaction-entry/components/transaction-type-form';
import {
    activeVersion,
    transactionTypesQuery,
    type TransactionType,
} from '@/modules/transaction-entry/transaction-types';

/**
 * Transaction types of the company (TE-FR-01): what each one is called, which version entries use,
 * and how its entries are numbered (TE-FR-16). Types are created here, brought in from a reviewed
 * template, and opened in the designer to change their versions.
 */
export function TransactionTypesPage({
    companyId,
    canManage,
}: {
    companyId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);
    const [importing, setImporting] = useState(false);
    const [numbering, setNumbering] = useState<TransactionType | null>(null);

    useDocumentTitle(t('transactionTypes.pageTitle'));

    const types = useQuery(transactionTypesQuery(companyId));

    const actions = canManage && (
        <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => setImporting(true)}>
                <SparklesIcon data-icon="inline-start" />
                {t('transactionTypes.importTemplate')}
            </Button>
            <Button onClick={() => setCreating(true)}>
                <PlusIcon data-icon="inline-start" />
                {t('transactionTypes.new')}
            </Button>
        </div>
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('transactionTypes.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('transactionTypes.description')}
                    </p>
                </div>
                {actions}
            </div>

            {types.isError ? (
                <FormAlert>{genericErrorMessage(types.error, t)}</FormAlert>
            ) : types.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : types.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <LayersIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('transactionTypes.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('transactionTypes.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {canManage && <EmptyContent>{actions}</EmptyContent>}
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('transactionTypes.columns.name')}
                                </TableHead>
                                <TableHead>
                                    {t('transactionTypes.columns.mode')}
                                </TableHead>
                                <TableHead>
                                    {t('transactionTypes.columns.version')}
                                </TableHead>
                                <TableHead>
                                    {t('transactionTypes.columns.numbering')}
                                </TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">
                                        {t('transactionTypes.columns.actions')}
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {types.data.map((type) => {
                                const active = activeVersion(type);
                                const draft = type.versions.find(
                                    (version) => version.status === 'draft',
                                );

                                return (
                                    <TableRow key={type.id}>
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <Link
                                                    to="/w/$companyId/transaction-types/$typeId"
                                                    params={{
                                                        companyId,
                                                        typeId: type.id,
                                                    }}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {type.label}
                                                </Link>
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {type.key}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {active ? (
                                                <Badge variant="outline">
                                                    {t(
                                                        `transactionTypes.modes.${active.mode}`,
                                                    )}
                                                </Badge>
                                            ) : (
                                                '–'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap items-center gap-2">
                                                {active ? (
                                                    <span className="tabular-nums">
                                                        {t(
                                                            'transactionTypes.version',
                                                            {
                                                                version:
                                                                    active.version,
                                                            },
                                                        )}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {t(
                                                            'transactionTypes.notPublished',
                                                        )}
                                                    </span>
                                                )}
                                                {draft && (
                                                    <Badge variant="secondary">
                                                        {t(
                                                            'transactionTypes.draftVersion',
                                                            {
                                                                version:
                                                                    draft.version,
                                                            },
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <span className="font-mono text-xs">
                                                    {type.numbering.pattern}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {t(
                                                        `transactionTypes.numbering.policies.${type.numbering.policy}`,
                                                    )}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <RowActions label={type.label}>
                                                <DropdownMenuItem
                                                    render={
                                                        <Link
                                                            to="/w/$companyId/transaction-types/$typeId"
                                                            params={{
                                                                companyId,
                                                                typeId: type.id,
                                                            }}
                                                        />
                                                    }
                                                >
                                                    {t(
                                                        'transactionTypes.actions.open',
                                                    )}
                                                </DropdownMenuItem>
                                                {canManage && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setNumbering(type)
                                                        }
                                                    >
                                                        {t(
                                                            'transactionTypes.actions.numbering',
                                                        )}
                                                    </DropdownMenuItem>
                                                )}
                                            </RowActions>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}

            <FormDialog
                open={creating}
                onOpenChange={setCreating}
                title={t('transactionTypes.createTitle')}
                description={t('transactionTypes.createDescription')}
            >
                <TransactionTypeForm
                    companyId={companyId}
                    onCreated={() => setCreating(false)}
                />
            </FormDialog>
            <TemplateImportDialog
                companyId={companyId}
                existingKeys={types.data?.map((type) => type.key) ?? []}
                open={importing}
                onOpenChange={setImporting}
            />
            <NumberingDialog
                companyId={companyId}
                type={numbering}
                onClose={() => setNumbering(null)}
            />
        </>
    );
}
