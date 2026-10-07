import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { FileTextIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Badge } from '@/components/ui/badge';
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
import { classificationsQuery } from '@/modules/reporting/classifications';
import { reportDefinitionsQuery } from '@/modules/reporting/report-definitions';

/** The report definitions of the company; each opens in the viewer (RP-FR-01). */
export function ReportsPage({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const definitions = useQuery(reportDefinitionsQuery(companyId));
    const classifications = useQuery(classificationsQuery(companyId));

    useDocumentTitle(t('reports.pageTitle'));

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('reports.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('reports.description')}
                </p>
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
                classifications.data.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-base font-medium">
                            {t('classifications.listTitle')}
                        </h2>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            {t('classifications.listDescription')}
                        </p>
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('reports.name')}</TableHead>
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
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                )}
        </>
    );
}
