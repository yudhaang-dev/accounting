import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { partyRolesQuery } from '@/modules/subledger/party-roles';
import { postingProfilesQuery } from '@/modules/subledger/posting-profiles';
import { postingRuleText } from '@/modules/transaction-entry/components/posting-rule-text';
import { accountNamesQuery } from '@/modules/transaction-entry/entries';
import type { TypeVersion } from '@/modules/transaction-entry/transaction-types';
import type { ROUTE_KINDS } from '@/modules/transaction-entry/components/version-editor';

type RouteKind = (typeof ROUTE_KINDS)[number];

/** A published or retired version as the package it is (TE-FR-02): immutable, so only read here. */
export function VersionSummary({
    companyId,
    version,
}: {
    companyId: string;
    version: TypeVersion;
}) {
    const { t } = useTranslation();
    const accounts = useQuery(accountNamesQuery(companyId));
    const partyRoles = useQuery(partyRolesQuery(companyId));
    const profiles = useQuery({
        ...postingProfilesQuery(companyId),
        enabled: Boolean(version.subledger_route?.posting_profile_id),
    });
    const roleLabel = (key: string) =>
        partyRoles.data?.find((role) => role.key === key)?.label ?? key;
    const routeProfile = profiles.data?.find(
        (profile) => profile.id === version.subledger_route?.posting_profile_id,
    );
    const fields = version.fields ?? [];
    const rules = version.posting_rules ?? [];
    const accountName = (id: string) => {
        const account = accounts.data?.[id];

        return account ? `${account.code} · ${account.name}` : undefined;
    };

    return (
        <div className="flex flex-col gap-5 text-sm">
            <dl className="grid gap-x-8 gap-y-3 sm:grid-cols-2">
                <div className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">
                        {t('transactionTypes.designer.mode')}
                    </dt>
                    <dd className="font-medium">
                        {t(`transactionTypes.modes.${version.mode}`)}
                    </dd>
                </div>
                <div className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">
                        {t('transactionTypes.designer.currencyPolicy')}
                    </dt>
                    <dd className="font-medium">
                        {version.allowed_currencies?.length
                            ? version.allowed_currencies.join(', ')
                            : t('transactionTypes.designer.anyCurrency')}
                        {version.allow_rate_input &&
                            ` · ${t('transactionTypes.designer.allowRateInput')}`}
                    </dd>
                </div>
                {version.subledger_route && (
                    <div className="flex flex-col gap-0.5">
                        <dt className="text-muted-foreground">
                            {t('transactionTypes.designer.route.title')}
                        </dt>
                        <dd className="font-medium">
                            {t(
                                `transactionTypes.designer.route.kinds.${version.subledger_route.kind as RouteKind}`,
                            )}
                            {routeProfile &&
                                ` · ${t('transactionTypes.designer.route.postingProfile')}: ${routeProfile.name}`}
                        </dd>
                    </div>
                )}
            </dl>

            {version.mode === 'structured' ? (
                <>
                    <section className="flex flex-col gap-2">
                        <h3 className="font-medium">
                            {t('transactionTypes.designer.fields')}
                        </h3>
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'transactionTypes.designer.fieldLabel',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'transactionTypes.designer.fieldName',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'transactionTypes.designer.fieldType',
                                            )}
                                        </TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {fields.map((field) => (
                                        <TableRow key={field.name}>
                                            <TableCell className="font-medium">
                                                {field.label}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">
                                                {field.name}
                                            </TableCell>
                                            <TableCell>
                                                {t(
                                                    `transactionTypes.fieldTypes.${field.type}`,
                                                )}
                                                {field.type === 'party' &&
                                                field.allowed_roles?.length ? (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {field.allowed_roles
                                                            .map(roleLabel)
                                                            .join(', ')}
                                                    </span>
                                                ) : null}
                                            </TableCell>
                                            <TableCell>
                                                {field.required === false ? (
                                                    <Badge variant="outline">
                                                        {t(
                                                            'transactionTypes.import.optional',
                                                        )}
                                                    </Badge>
                                                ) : null}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                    <section className="flex flex-col gap-2">
                        <h3 className="font-medium">
                            {t('transactionTypes.designer.rules')}
                        </h3>
                        <ol className="flex list-decimal flex-col gap-1 ps-5">
                            {rules.map((rule, index) => (
                                <li key={index}>
                                    {postingRuleText(
                                        t,
                                        rule,
                                        fields,
                                        accountName,
                                    )}
                                    {rule.reuses_field && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            (
                                            {t(
                                                'transactionTypes.designer.reusesField',
                                            )}
                                            )
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </section>
                </>
            ) : (
                <p className="text-muted-foreground">
                    {t('transactionTypes.designer.modeHint.free_lines')}
                </p>
            )}
        </div>
    );
}
