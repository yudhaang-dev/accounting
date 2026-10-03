import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    DownloadIcon,
    PaperclipIcon,
    Trash2Icon,
    UploadIcon,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useLastDefined } from '@/hooks/use-last-defined';
import { formatBytes } from '@/lib/bytes';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    ATTACHMENT_ACCEPT,
    ATTACHMENT_MAX_MB,
    attachmentsQuery,
    downloadAttachment,
    removeAttachment,
    uploadAttachment,
    type Attachment,
} from '@/modules/transaction-entry/attachments';
import type { Entry } from '@/modules/transaction-entry/entries';

/**
 * Files kept with an entry (X-FR-04): receipts, invoices, payroll sheets. A file can be added until the
 * entry is posted or cancelled and taken off only while it is a draft or rejected; the record of it stays.
 */
export function AttachmentsCard({
    companyId,
    entry,
    canChange,
}: {
    companyId: string;
    entry: Entry;
    /** Whether the user may change the entry (`entries.create`); reading is for everyone who sees the entry. */
    canChange: boolean;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const input = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | null>(null);
    const [removing, setRemoving] = useState<Attachment | null>(null);
    const asking = useLastDefined(removing);
    const files = useQuery(attachmentsQuery(companyId, entry.id));
    const canAdd =
        canChange && entry.status !== 'posted' && entry.status !== 'cancelled';
    const canRemove =
        canChange && (entry.status === 'draft' || entry.status === 'rejected');

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: [
                'companies',
                companyId,
                'entries',
                entry.id,
                'attachments',
            ],
        });

    const upload = useMutation({
        mutationFn: (file: File) => uploadAttachment(companyId, entry.id, file),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t('entryAttachments.added', { name: saved.filename }),
            );
        },
        onError: (error) =>
            setError(
                isApiError(error) && error.status === 422
                    ? (Object.values(error.errors).flat()[0] ?? error.message)
                    : actionErrorMessage(error, t),
            ),
    });

    const remove = useMutation({
        mutationFn: (attachment: Attachment) =>
            removeAttachment(companyId, entry.id, attachment.id),
        onSuccess: async (_, attachment) => {
            await refresh();
            toast.success(
                t('entryAttachments.removed', { name: attachment.filename }),
            );
        },
        onError: (error) => setError(actionErrorMessage(error, t)),
        onSettled: () => setRemoving(null),
    });

    const download = useMutation({
        mutationFn: (attachment: Attachment) =>
            downloadAttachment(companyId, entry.id, attachment),
        onError: (error) => setError(actionErrorMessage(error, t)),
    });

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <CardTitle>{t('entryAttachments.title')}</CardTitle>
                {canAdd && (
                    <>
                        <input
                            ref={input}
                            type="file"
                            hidden
                            accept={ATTACHMENT_ACCEPT}
                            aria-label={t('entryAttachments.choose')}
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                event.target.value = '';

                                if (file) {
                                    setError(null);
                                    upload.mutate(file);
                                }
                            }}
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={upload.isPending}
                            onClick={() => input.current?.click()}
                        >
                            {upload.isPending ? (
                                <Spinner data-icon="inline-start" aria-hidden />
                            ) : (
                                <UploadIcon data-icon="inline-start" />
                            )}
                            {t('entryAttachments.add')}
                        </Button>
                    </>
                )}
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {error && <FormAlert>{error}</FormAlert>}

                {files.isError ? (
                    <FormAlert>{genericErrorMessage(files.error, t)}</FormAlert>
                ) : files.data === undefined ? (
                    <Skeleton className="h-12 w-full" />
                ) : files.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {canAdd
                            ? t('entryAttachments.emptyCanAdd', {
                                  size: ATTACHMENT_MAX_MB,
                              })
                            : t('entryAttachments.empty')}
                    </p>
                ) : (
                    <ul className="flex flex-col divide-y rounded-lg border">
                        {files.data.map((file) => (
                            <li
                                key={file.id}
                                className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm"
                            >
                                <PaperclipIcon
                                    className="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden
                                />
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate font-medium">
                                        {file.filename}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {formatBytes(
                                            file.size_bytes,
                                            i18n.language,
                                        )}
                                        {file.created_at &&
                                            ` · ${new Date(file.created_at).toLocaleString(i18n.language)}`}
                                    </span>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={t('entryAttachments.download', {
                                        name: file.filename,
                                    })}
                                    onClick={() => {
                                        setError(null);
                                        download.mutate(file);
                                    }}
                                >
                                    <DownloadIcon aria-hidden />
                                </Button>
                                {canRemove && (
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={t(
                                            'entryAttachments.remove',
                                            { name: file.filename },
                                        )}
                                        onClick={() => setRemoving(file)}
                                    >
                                        <Trash2Icon aria-hidden />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRemoving(null);
                    }
                }}
                title={t('entryAttachments.removeTitle', {
                    name: asking?.filename ?? '',
                })}
                description={t('entryAttachments.removeDescription')}
                confirmLabel={t('entryAttachments.removeAction')}
                destructive
                pending={remove.isPending}
                onConfirm={() => removing && remove.mutate(removing)}
            />
        </Card>
    );
}
