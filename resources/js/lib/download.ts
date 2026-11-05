import {
    ApiError,
    apiErrorFrom,
    defaultHeaders,
    type ApiErrorBody,
} from '@/lib/http';

/** File name from a `Content-Disposition` header, if it carries one. */
export function fileNameFrom(header: string | null): string | undefined {
    const match = header?.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);

    return match?.[1] ? decodeURIComponent(match[1]) : undefined;
}

/**
 * Downloads a file the API produces for the same address and permission as the JSON it serves
 * (X-FR-02): the session cookie authorises it, `Accept` picks the format. Rejects with an `ApiError`.
 */
export async function downloadFile(
    url: string,
    accept: string,
    fallbackName: string,
): Promise<void> {
    let response: Response;

    try {
        response = await fetch(url, {
            credentials: 'same-origin',
            headers: { ...defaultHeaders('GET'), Accept: accept },
        });
    } catch {
        // A `Response` cannot have status 0, so the offline failure is built directly.
        throw new ApiError(0, 'Network error', 'NETWORK');
    }

    if (!response.ok) {
        let body: Partial<ApiErrorBody> = {};

        try {
            body = (await response.json()) as Partial<ApiErrorBody>;
        } catch {
            // Not JSON: the status alone will do.
        }

        throw apiErrorFrom(response, body);
    }

    const blob = await response.blob();
    const name =
        fileNameFrom(response.headers.get('Content-Disposition')) ??
        fallbackName;
    const link = document.createElement('a');
    const objectUrl = URL.createObjectURL(blob);

    link.href = objectUrl;
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
}

/** The formats a report can be downloaded in (ADR-0018); `Accept` picks one. */
export type ExportFormat = 'csv' | 'xlsx' | 'pdf';

export const EXPORT_FORMATS: Record<
    ExportFormat,
    { accept: string; extension: string }
> = {
    csv: { accept: 'text/csv', extension: 'csv' },
    xlsx: {
        accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        extension: 'xlsx',
    },
    pdf: { accept: 'application/pdf', extension: 'pdf' },
};

/** Downloads `address` in `format`; `baseName` is the file name without extension, used when the API names none. */
export function downloadReport(
    address: string,
    format: ExportFormat,
    baseName: string,
): Promise<void> {
    const { accept, extension } = EXPORT_FORMATS[format];

    return downloadFile(address, accept, `${baseName}.${extension}`);
}
