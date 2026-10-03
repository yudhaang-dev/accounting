import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { downloadFile } from '@/lib/download';

export type Attachment = components['schemas']['AttachmentResource'];

/** The types the API accepts (config `attachments.types`); the input offers only these. */
export const ATTACHMENT_ACCEPT = '.pdf,.png,.jpg,.jpeg,.csv,.txt,.xlsx,.docx';
export const ATTACHMENT_MAX_MB = 10;

export const attachmentsQuery = (companyId: string, entryId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'entries', entryId, 'attachments'],
        queryFn: async (): Promise<Attachment[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/entries/{id}/attachments', {
                        params: { path: { companyId, id: entryId } },
                    }),
                )
            ).data,
    });

export async function uploadAttachment(
    companyId: string,
    entryId: string,
    file: File,
): Promise<Attachment> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/entries/{id}/attachments', {
                params: { path: { companyId, id: entryId } },
                body: { file: file as unknown as string },
                // The API takes the file as a multipart field, not as JSON.
                bodySerializer: () => {
                    const form = new FormData();

                    form.append('file', file, file.name);

                    return form;
                },
            }),
        )
    ).data;
}

export async function removeAttachment(
    companyId: string,
    entryId: string,
    attachmentId: string,
): Promise<void> {
    await unwrap(
        api.DELETE(
            '/companies/{companyId}/entries/{id}/attachments/{attachmentId}',
            { params: { path: { companyId, id: entryId, attachmentId } } },
        ),
    );
}

export function downloadAttachment(
    companyId: string,
    entryId: string,
    attachment: Attachment,
): Promise<void> {
    return downloadFile(
        `/api/v1/companies/${companyId}/entries/${entryId}/attachments/${attachment.id}`,
        'application/octet-stream',
        attachment.filename,
    );
}
