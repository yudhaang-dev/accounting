import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { SearchIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { TransactionTypeForm } from '@/modules/transaction-entry/components/transaction-type-form';
import {
    transactionTypesQuery,
    usableTypes,
    type TransactionType,
} from '@/modules/transaction-entry/transaction-types';

/** Whether the type answers a search: its name, its key or what the accountant wrote about it. */
function matches(type: TransactionType, needle: string): boolean {
    return [type.label, type.key, type.description ?? ''].some((text) =>
        text.toLowerCase().includes(needle),
    );
}

const linkClass =
    'group block h-full rounded-xl outline-none focus-visible:ring-3 focus-visible:ring-ring/50';

/** One card of the catalog, leading to the form of the type for what the operator is about to make. */
function TypeLink({
    companyId,
    purpose,
    type,
}: {
    companyId: string;
    purpose: 'entry' | 'schedule';
    type: TransactionType;
}) {
    const card = (
        <Card className="h-full transition-colors group-hover:bg-muted/40">
            <CardHeader>
                <CardTitle>{type.label}</CardTitle>
                {type.description && (
                    <CardDescription>{type.description}</CardDescription>
                )}
            </CardHeader>
        </Card>
    );

    return purpose === 'schedule' ? (
        <Link
            to="/w/$companyId/recurring-schedules/new"
            params={{ companyId }}
            search={{ type: type.key }}
            className={linkClass}
        >
            {card}
        </Link>
    ) : (
        <Link
            to="/w/$companyId/entries/new"
            params={{ companyId }}
            search={{ type: type.key }}
            className={linkClass}
        >
            {card}
        </Link>
    );
}

/**
 * Where a new entry starts (TE-FR-01): the transaction types an operator can use, as cards with a search,
 * each leading to the form of that type. Nothing is chosen for the operator, so the entry is never made
 * on a type that happened to come first. A new recurring schedule (TE-FR-14) starts from the same catalog
 * and leads to the schedule form.
 */
export function EntryCatalogPage({
    companyId,
    canManageTypes,
    purpose = 'entry',
}: {
    companyId: string;
    canManageTypes: boolean;
    purpose?: 'entry' | 'schedule';
}) {
    const { t } = useTranslation();
    const types = useQuery(transactionTypesQuery(companyId));
    const [search, setSearch] = useState('');
    const scope = purpose === 'schedule' ? 'schedules' : 'entries';

    useDocumentTitle(t(`${scope}.catalog.title`));

    const usable = useMemo(
        () =>
            usableTypes(types.data ?? []).sort((a, b) =>
                a.label.localeCompare(b.label, 'id'),
            ),
        [types.data],
    );
    const needle = search.trim().toLowerCase();
    const shown =
        needle === '' ? usable : usable.filter((type) => matches(type, needle));

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t(`${scope}.catalog.title`)}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t(`${scope}.catalog.description`)}
                </p>
            </div>

            {types.isError ? (
                <FormAlert>{genericErrorMessage(types.error, t)}</FormAlert>
            ) : types.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : types.data.length === 0 ? (
                canManageTypes ? (
                    <div className="max-w-xl">
                        <TransactionTypeForm companyId={companyId} />
                    </div>
                ) : (
                    <FormAlert>{t('entries.types.noneDescription')}</FormAlert>
                )
            ) : usable.length === 0 ? (
                <FormAlert>{t('entries.types.noneUsable')}</FormAlert>
            ) : (
                <>
                    <InputGroup className="w-full sm:w-96">
                        <InputGroupAddon>
                            <SearchIcon aria-hidden />
                        </InputGroupAddon>
                        <InputGroupInput
                            type="search"
                            value={search}
                            placeholder={t('entries.catalog.searchPlaceholder')}
                            aria-label={t('entries.catalog.search')}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </InputGroup>

                    {shown.length === 0 ? (
                        <Empty className="max-w-xl flex-none border py-10">
                            <EmptyHeader>
                                <EmptyTitle>
                                    {t('entries.catalog.noMatch')}
                                </EmptyTitle>
                                <EmptyDescription>
                                    {t('entries.catalog.noMatchDescription')}
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {shown.map((type) => (
                                <li key={type.id}>
                                    <TypeLink
                                        companyId={companyId}
                                        purpose={purpose}
                                        type={type}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </div>
    );
}
