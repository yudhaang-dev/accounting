import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Skeleton } from '@/components/ui/skeleton';
import { genericErrorMessage } from '@/lib/errors';
import { rolesQuery } from '@/modules/identity-access/access';
import {
    CheckItem,
    toggled,
} from '@/modules/identity-access/components/check-item';

/**
 * The roles of the company as checkboxes (IA-FR-02). A deactivated role is offered only while the
 * member still holds it, so it can be taken off but not handed out again.
 */
export function RolePicker({
    companyId,
    value,
    onChange,
}: {
    companyId: string;
    value: string[];
    onChange: (value: string[]) => void;
}) {
    const { t } = useTranslation();
    const roles = useQuery(rolesQuery(companyId));

    if (roles.isError) {
        return <FormAlert>{genericErrorMessage(roles.error, t)}</FormAlert>;
    }

    if (roles.data === undefined) {
        return <Skeleton className="h-20 w-full" />;
    }

    const offered = roles.data.filter(
        (role) => role.is_active || value.includes(role.id),
    );

    return (
        <div className="flex flex-col gap-2">
            {offered.map((role) => (
                <CheckItem
                    key={role.id}
                    id={`member-role-${role.id}`}
                    label={role.name}
                    description={
                        role.is_active ? undefined : t('members.roleInactive')
                    }
                    checked={value.includes(role.id)}
                    onChange={(on) => onChange(toggled(value, role.id, on))}
                />
            ))}
        </div>
    );
}
