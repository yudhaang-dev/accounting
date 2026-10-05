import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { publishVersion, type PlanVersion } from '@/modules/planning/plans';

/**
 * Asks before a draft is published, because a published version no longer changes (PL-FR-01). The
 * server may refuse: without lines, or when the policy wants a publisher other than the last editor
 * (PL-AC-03); its words are shown as they come.
 */
export function PublishDialog({
    companyId,
    version,
    onOpenChange,
}: {
    companyId: string;
    /** The draft to publish; none closes the dialog. */
    version: PlanVersion | null;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [failure, setFailure] = useState<string | null>(null);

    // Each draft is asked about afresh.
    useEffect(() => setFailure(null), [version?.id]);

    const publish = useMutation({
        mutationFn: (target: PlanVersion) =>
            publishVersion(companyId, target.id, target.lock_version),
        onSuccess: async (published) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'plans'],
            });
            toast.success(t('plans.publish.done', { name: published.name }));
            onOpenChange(false);
        },
        onError: async (error) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'plans'],
            });
            setFailure(
                isApiError(error) && error.code === 'VERSION_CONFLICT'
                    ? t('plans.publish.conflict')
                    : actionErrorMessage(error, t),
            );
        },
    });

    return (
        <ConfirmDialog
            open={version !== null}
            onOpenChange={onOpenChange}
            title={t('plans.publish.title', { name: version?.name ?? '' })}
            description={t('plans.publish.description')}
            confirmLabel={t('plans.publish.action')}
            pending={publish.isPending}
            error={failure}
            onConfirm={() => version && publish.mutate(version)}
        />
    );
}
