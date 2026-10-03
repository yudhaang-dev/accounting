/** A file size people can read: "820 B", "1,4 MB". The decimal mark follows the language. */
export function formatBytes(bytes: number, language: string): string {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${new Intl.NumberFormat(language, {
        maximumFractionDigits: unit === 0 ? 0 : 1,
    }).format(value)} ${units[unit]}`;
}
