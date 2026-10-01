import { useSuspenseQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { companyQuery } from '@/modules/master-data/company';

const STATUS_VARIANT = {
    setup: 'secondary',
    active: 'default',
    inactive: 'outline',
} as const;

/** Overview of the company in use: its profile and where it stands in the setup. */
export function CompanyHomePage({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const { data: company } = useSuspenseQuery(companyQuery(companyId));

    useDocumentTitle(t('company.pageTitle', { name: company.name }));

    const status = company.status as keyof typeof STATUS_VARIANT;
    const notSet = t('company.notSet');
    const profile: [string, string][] = [
        [t('company.legalName'), company.legal_name ?? notSet],
        [t('company.taxId'), company.tax_id ?? notSet],
        [t('company.currency'), company.functional_currency],
        [t('company.timezone'), company.timezone],
    ];

    return (
        <>
            <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-xl font-semibold tracking-tight">
                    {company.name}
                </h1>
                <Badge variant={STATUS_VARIANT[status] ?? 'outline'}>
                    {t(`company.status.${status}`, {
                        defaultValue: company.status,
                    })}
                </Badge>
            </div>

            {status === 'setup' && (
                <Alert className="max-w-xl">
                    <AlertTitle>{t('company.setupTitle')}</AlertTitle>
                    <AlertDescription>
                        {t('company.setupDescription')}
                    </AlertDescription>
                </Alert>
            )}

            <Card className="max-w-xl">
                <CardHeader>
                    <CardTitle>{t('company.profile')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[auto_1fr]">
                        {profile.map(([label, value]) => (
                            <div
                                key={label}
                                className="grid gap-1 sm:col-span-2 sm:grid-cols-subgrid"
                            >
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="font-medium">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </CardContent>
            </Card>
        </>
    );
}
