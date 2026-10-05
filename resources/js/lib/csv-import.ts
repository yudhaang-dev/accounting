/** The text of a chosen file. */
export const readText = (file: File): Promise<string> =>
    new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = () =>
            resolve(typeof reader.result === 'string' ? reader.result : '');
        reader.onerror = () => reject(reader.error);
        reader.readAsText(file);
    });

/** How many problems an import dialog lists; the rest are counted. */
export const SHOWN_PROBLEMS = 20;

export type Problem = { line: string; field: string; message: string };

/** `rows.3.currency` is line 3, field currency; `rows.3` is the line as a whole; anything else has no line. */
export function problemsOf(errors: Record<string, string[]>): Problem[] {
    return Object.entries(errors).flatMap(([key, messages]) => {
        const match = key.match(/^rows\.(\d+)(?:\.(.+))?$/);

        return messages.map((message) => ({
            line: match?.[1] ?? '',
            field: match?.[2] ?? (match ? '' : key),
            message,
        }));
    });
}
