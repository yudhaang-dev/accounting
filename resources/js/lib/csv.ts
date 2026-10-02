/**
 * Reads CSV text into rows of cells. The separator is taken from the first line (comma, semicolon or
 * tab, as spreadsheets in different locales write them); quoted cells may hold the separator, line
 * breaks and doubled quotes. Blank lines are skipped and cells are trimmed.
 */
export function parseCsv(text: string): string[][] {
    const source = text.replace(/^﻿/, '');
    const firstLine = source.split(/\r?\n/, 1)[0] ?? '';
    const separator =
        [';', '\t', ','].find((candidate) => firstLine.includes(candidate)) ??
        ',';
    const rows: string[][] = [];
    let row: string[] = [];
    let cell = '';
    let quoted = false;

    const endCell = () => {
        row.push(cell.trim());
        cell = '';
    };
    const endRow = () => {
        endCell();

        if (row.some((value) => value !== '')) {
            rows.push(row);
        }

        row = [];
    };

    for (let index = 0; index < source.length; index += 1) {
        const char = source[index];

        if (quoted) {
            if (char === '"' && source[index + 1] === '"') {
                cell += '"';
                index += 1;
            } else if (char === '"') {
                quoted = false;
            } else {
                cell += char;
            }
        } else if (char === '"') {
            quoted = true;
        } else if (char === separator) {
            endCell();
        } else if (char === '\n' || char === '\r') {
            if (char === '\r' && source[index + 1] === '\n') {
                index += 1;
            }

            endRow();
        } else {
            cell += char;
        }
    }

    endRow();

    return rows;
}
