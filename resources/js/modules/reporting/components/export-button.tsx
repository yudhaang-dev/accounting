import { useMutation } from '@tanstack/react-query';
import { DownloadIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { isApiError } from '@/lib/http';

/** Downloads a report as CSV under the same permission and filters as the screen (X-FR-02). */
export function ExportButton({
    onExport,
    disabled = false,
}: {
    onExport: () => Promise<void>;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const download = useMutation({
        mutationFn: onExport,
        onError: (error) =>
            toast.error(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : t('reports.exportFailed'),
            ),
    });

    return (
        <Button
            variant="outline"
            disabled={disabled || download.isPending}
            aria-busy={download.isPending}
            onClick={() => download.mutate()}
        >
            {download.isPending ? (
                <Spinner data-icon="inline-start" aria-hidden />
            ) : (
                <DownloadIcon data-icon="inline-start" />
            )}
            {download.isPending ? t('reports.exporting') : t('reports.export')}
        </Button>
    );
}
