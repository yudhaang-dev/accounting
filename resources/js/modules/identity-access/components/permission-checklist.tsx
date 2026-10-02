import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Skeleton } from '@/components/ui/skeleton';
import { genericErrorMessage } from '@/lib/errors';
import {
    groupByResource,
    permissionsQuery,
} from '@/modules/identity-access/access';
import {
    CheckItem,
    toggled,
} from '@/modules/identity-access/components/check-item';

/**
 * The permission catalog as checkboxes grouped by resource, for a role (IA-FR-02) or for the
 * permissions a policy guards with 2FA (IA-FR-05). The keys are the system's own identifiers, so they
 * are shown as they are.
 */
export function PermissionChecklist({
    companyId,
    idPrefix,
    value,
    onChange,
    disabled,
}: {
    companyId: string;
    idPrefix: string;
    value: string[];
    onChange: (value: string[]) => void;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const catalog = useQuery(permissionsQuery(companyId));

    if (catalog.isError) {
        return <FormAlert>{genericErrorMessage(catalog.error, t)}</FormAlert>;
    }

    if (catalog.data === undefined) {
        return <Skeleton className="h-40 w-full" />;
    }

    return (
        <div className="grid max-h-72 gap-4 overflow-y-auto rounded-lg border p-3 sm:grid-cols-2">
            {groupByResource(catalog.data).map(({ resource, keys }) => (
                <fieldset key={resource} className="flex flex-col gap-2">
                    <legend className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {resource}
                    </legend>
                    {keys.map((key) => (
                        <CheckItem
                            key={key}
                            id={`${idPrefix}-${key}`}
                            label={
                                <span className="font-mono text-xs">{key}</span>
                            }
                            checked={value.includes(key)}
                            disabled={disabled}
                            onChange={(on) => onChange(toggled(value, key, on))}
                        />
                    ))}
                </fieldset>
            ))}
        </div>
    );
}
