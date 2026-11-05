import { useMutation } from '@tanstack/react-query';
import { ChevronDownIcon, DownloadIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { type ExportFormat } from '@/lib/download';
import { isApiError } from '@/lib/http';

/**
 * Downloads a report under the same permission and filters as the screen (X-FR-02). With one format
 * it is a button; with several (ADR-0018) it opens a menu of them. A refusal such as a file over the
 * row limit shows the message of the API.
 */
export function ExportButton({
    onExport,
    formats = ['csv'],
    disabled = false,
}: {
    onExport: (format: ExportFormat) => Promise<void>;
    formats?: ExportFormat[];
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
    const busy = download.isPending;
    const icon = busy ? (
        <Spinner data-icon="inline-start" aria-hidden />
    ) : (
        <DownloadIcon data-icon="inline-start" />
    );

    if (formats.length === 1) {
        return (
            <Button
                variant="outline"
                disabled={disabled || busy}
                aria-busy={busy}
                onClick={() => download.mutate(formats[0])}
            >
                {icon}
                {busy ? t('reports.exporting') : t('reports.export')}
            </Button>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        variant="outline"
                        disabled={disabled || busy}
                        aria-busy={busy}
                    />
                }
            >
                {icon}
                {busy ? t('reports.exporting') : t('reports.exportMenu')}
                <ChevronDownIcon data-icon="inline-end" aria-hidden />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {formats.map((format) => (
                    <DropdownMenuItem
                        key={format}
                        onClick={() => download.mutate(format)}
                    >
                        {t(`reports.formats.${format}`)}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
