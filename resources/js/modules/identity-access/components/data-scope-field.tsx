import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Skeleton } from '@/components/ui/skeleton';
import { genericErrorMessage } from '@/lib/errors';
import {
    dimensionTypesQuery,
    dimensionValuesQuery,
    inTreeOrder,
    valueDepths,
    type DimensionType,
} from '@/modules/master-data/dimensions';
import {
    CheckItem,
    toggled,
} from '@/modules/identity-access/components/check-item';

function TypeScope({
    companyId,
    type,
    value,
    onChange,
}: {
    companyId: string;
    type: DimensionType;
    value: string[];
    onChange: (value: string[]) => void;
}) {
    const { t } = useTranslation();
    const values = useQuery(dimensionValuesQuery(companyId, type.id));

    if (values.isError) {
        return <FormAlert>{genericErrorMessage(values.error, t)}</FormAlert>;
    }

    if (values.data === undefined) {
        return <Skeleton className="h-16 w-full" />;
    }

    const depths = valueDepths(values.data);

    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">{type.label}</legend>
            {inTreeOrder(values.data).map((item) => (
                <div
                    key={item.id}
                    style={{
                        paddingInlineStart: `${(depths.get(item.id) ?? 0) * 1.25}rem`,
                    }}
                >
                    <CheckItem
                        id={`scope-${item.id}`}
                        label={`${item.code} · ${item.name}`}
                        checked={value.includes(item.id)}
                        onChange={(on) => onChange(toggled(value, item.id, on))}
                    />
                </div>
            ))}
        </fieldset>
    );
}

/**
 * Limits a member to dimension values (IA-FR-03). Every ticked value stands for itself and everything
 * below it, so a branch added under it later is covered without touching the member; nothing ticked
 * means no limit. Values of one type add up, and the types narrow each other.
 */
export function DataScopeField({
    companyId,
    value,
    onChange,
}: {
    companyId: string;
    value: string[];
    onChange: (value: string[]) => void;
}) {
    const { t } = useTranslation();
    const types = useQuery(dimensionTypesQuery(companyId));

    if (types.isError) {
        return <FormAlert>{genericErrorMessage(types.error, t)}</FormAlert>;
    }

    if (types.data === undefined) {
        return <Skeleton className="h-24 w-full" />;
    }

    if (types.data.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('members.scope.noDimensions')}
            </p>
        );
    }

    return (
        <div className="flex max-h-64 flex-col gap-4 overflow-y-auto rounded-lg border p-3">
            {types.data.map((type) => (
                <TypeScope
                    key={type.id}
                    companyId={companyId}
                    type={type}
                    value={value}
                    onChange={onChange}
                />
            ))}
        </div>
    );
}
