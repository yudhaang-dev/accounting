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
import { reportDefinitionsQuery } from '@/modules/reporting/report-definitions';

/** The report definitions of the company; each opens in the viewer (RP-FR-01). */
export function ReportsPage({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const definitions = useQuery(reportDefinitionsQuery(companyId));

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
        </>
    );
}
