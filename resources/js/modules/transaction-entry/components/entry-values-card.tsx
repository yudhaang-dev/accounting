import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDecimal } from '@/lib/decimal';
import {
    accountNamesQuery,
    dimensionNamesQuery,
    type Entry,
} from '@/modules/transaction-entry/entries';
import { versionSchemaQuery } from '@/modules/transaction-entry/field-schema';
import { transactionTypesQuery } from '@/modules/transaction-entry/transaction-types';

/**
 * What the user typed into a structured entry, under the labels of its version (TE-FR-04). The journal
 * lines below it are what the posting rules made of these values.
 */
export function EntryValuesCard({
    companyId,
    entry,
    canReadDimensions,
}: {
    companyId: string;
    entry: Entry;
    canReadDimensions: boolean;
}) {
    const { t, i18n } = useTranslation();
    const types = useQuery(transactionTypesQuery(companyId));
    const typeId = types.data?.find(
        (type) => type.key === entry.transaction_type_key,
    )?.id;
    const schema = useQuery({
        ...versionSchemaQuery(
            companyId,
            typeId ?? '',
            entry.transaction_type_version,
        ),
        enabled: typeId !== undefined,
    });
    const accounts = useQuery(accountNamesQuery(companyId));
    const dimensions = useQuery({
        ...dimensionNamesQuery(companyId),
        enabled: canReadDimensions,
    });
    const values = (entry.values ?? {}) as Record<string, unknown>;

    if (!entry.values) {
        return null;
    }

    const show = (kind: string, value: string): string => {
        if (kind === 'amount') {
            return formatDecimal(value, i18n.language);
        }

        if (kind === 'account') {
            const account = accounts.data?.[value];

            return account ? `${account.code} · ${account.name}` : value;
        }

        if (kind === 'dimension') {
            const found = dimensions.data?.values[value];

            return found ? `${found.code} · ${found.name}` : value;
        }

        return value;
    };

    const rows = (schema.data ?? []).filter(
        (field) => typeof values[field.name] === 'string',
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('entries.detail.values')}</CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                    {rows.map((field) => (
                        <div key={field.name} className="flex flex-col gap-0.5">
                            <dt className="text-muted-foreground">
                                {field.label}
                            </dt>
                            <dd className="font-medium">
                                {show(field.kind, values[field.name] as string)}
                            </dd>
                        </div>
                    ))}
                    {entry.currency && (
                        <div className="flex flex-col gap-0.5">
                            <dt className="text-muted-foreground">
                                {t('entries.detail.currency')}
                            </dt>
                            <dd className="font-medium">
                                {entry.currency}
                                {entry.exchange_rate
                                    ? ` · ${t('entries.detail.rate', { rate: entry.exchange_rate })}`
                                    : ''}
                            </dd>
                        </div>
                    )}
                </dl>
            </CardContent>
        </Card>
    );
}
