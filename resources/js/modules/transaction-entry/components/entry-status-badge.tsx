import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';

const VARIANT = {
    draft: 'outline',
    submitted: 'secondary',
    approved: 'secondary',
    posted: 'default',
    rejected: 'destructive',
    cancelled: 'outline',
} as const;

/** Status of an entry in the lifecycle of TE-FR-07. */
export function EntryStatusBadge({ status }: { status: string }) {
    const { t } = useTranslation();

    return (
        <Badge variant={VARIANT[status as keyof typeof VARIANT] ?? 'outline'}>
            {t(`entries.statuses.${status}`, { defaultValue: status })}
        </Badge>
    );
}
