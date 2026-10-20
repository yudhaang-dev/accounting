import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { FileTextIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
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
import { genericErrorMessage } from '@/lib/errors';
import { ReportTemplateImportDialog } from '@/modules/reporting/components/template-import-dialog';
import { classificationsQuery } from '@/modules/reporting/classifications';
import { reportDefinitionsQuery } from '@/modules/reporting/report-definitions';

/** The report definitions of the company; each opens in the viewer (RP-FR-01). */
export function ReportsPage({
    companyId,
    canManage,
    canReadAccounts,
}: {
    companyId: string;
    canManage: boolean;
    canReadAccounts: boolean;
}) {
    const { t } = useTranslation();
    const definitions = useQuery(reportDefinitionsQuery(companyId));
    const classifications = useQuery(classificationsQuery(companyId));
    const [importing, setImporting] = useState(false);

    useDocumentTitle(t('reports.pageTitle'));

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('reports.title')}
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('reports.description')}
                    </p>
                </div>
                {canManage && (
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setImporting(true)}
                        >
                            {t('reportImport.button')}
                        </Button>
                    {canManage && (
                        <Link
                            to="/w/$companyId/reports/new"
                            params={{ companyId }}
                            className={buttonVariants()}
                        >
                            {t('definitionEditor.newButton')}
                        </Link>
                    )}
                    </div>
                )}
            </div>

            {definitions.isError ? (
                <FormAlert>
                    {genericErrorMessage(definitions.error, t)}
                </FormAlert>
            ) : definitions.data === undefined ? (
                <Skeleton className="h-40 w-full" />
            ) : definitions.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <FileTextIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('reports.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('reports.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('reports.name')}</TableHead>
                                <TableHead>{t('reports.key')}</TableHead>
                                <TableHead>{t('reports.kind')}</TableHead>
                                <TableHead className="text-end">
                                    {t('reports.version')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {definitions.data.map((definition) => (
                                <TableRow key={definition.id}>
                                    <TableCell>
                                        <Link
                                            to="/w/$companyId/reports/$definitionId"
                                            params={{
                                                companyId,
                                                definitionId: definition.id,
                                            }}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {definition.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {definition.key}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="secondary">
                                            {t(
                                                `reports.kinds.${definition.kind}`,
                                                {
                                                    defaultValue:
                                                        definition.kind,
                                                },
                                            )}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {definition.current_version}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <p className="text-sm">
                <Link
                    to="/w/$companyId/reports/cash-equivalents"
                    params={{ companyId }}
                    className="underline underline-offset-4"
                >
                    {t('cashEquivalents.link')}
                </Link>
            </p>

            {classifications.data !== undefined &&
                (classifications.data.length > 0 || canManage) && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-base font-medium">
                            {t('classifications.listTitle')}
                        </h2>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            {t('classifications.listDescription')}
                        </p>
                        {canManage && (
                            <Link
                                to="/w/$companyId/reports/classifications/new"
                                params={{ companyId }}
                                className="self-start text-sm underline underline-offset-4"
                            >
                                {t('classificationEditor.newButton')}
                            </Link>
                        )}
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('reports.name')}
                                        </TableHead>
                                        <TableHead>
                                            {t('classifications.reports')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {classifications.data.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell>{item.name}</TableCell>
                                            <TableCell className="flex flex-wrap gap-4">
                                                <Link
                                                    to="/w/$companyId/reports/classifications/$classificationId/cash-flow"
                                                    params={{
                                                        companyId,
                                                        classificationId:
                                                            item.id,
                                                    }}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {t(
                                                        'classifications.cashFlow.title',
                                                    )}
                                                </Link>
                                                <Link
                                                    to="/w/$companyId/reports/classifications/$classificationId/equity-changes"
                                                    params={{
                                                        companyId,
                                                        classificationId:
                                                            item.id,
                                                    }}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {t(
                                                        'classifications.equity.title',
                                                    )}
                                                </Link>
                                                {canManage && (
                                                    <Link
                                                        to="/w/$companyId/reports/classifications/$classificationId/edit"
                                                        params={{
                                                            companyId,
                                                            classificationId:
                                                                item.id,
                                                        }}
                                                        aria-label={t(
                                                            'classificationEditor.editNamed',
                                                            { name: item.name },
                                                        )}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {t(
                                                            'classificationEditor.edit',
                                                        )}
                                                    </Link>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                )}
            {canManage && (
                <ReportTemplateImportDialog
                    companyId={companyId}
                    existingKeys={(definitions.data ?? []).map(
                        (definition) => definition.key,
                    )}
                    canReadAccounts={canReadAccounts}
                    open={importing}
                    onOpenChange={setImporting}
                />
            )}
        </>
    );
}
