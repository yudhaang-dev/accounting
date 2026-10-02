import { describe, expect, it } from 'vitest';
import { parseCsv } from '@/lib/csv';

describe('parseCsv', () => {
    it('reads rows and trims the cells', () => {
        expect(parseCsv('code, debit ,credit\n1111,100.00,0\n')).toEqual([
            ['code', 'debit', 'credit'],
            ['1111', '100.00', '0'],
        ]);
    });

    it('takes the separator from the first line', () => {
        expect(parseCsv('code;debit\n1111;100,50')).toEqual([
            ['code', 'debit'],
            ['1111', '100,50'],
        ]);
        expect(parseCsv('code\tdebit\n1111\t100')).toEqual([
            ['code', 'debit'],
            ['1111', '100'],
        ]);
    });

    it('keeps separators, line breaks and quotes inside quoted cells', () => {
        expect(parseCsv('a,b\n"x, y","say ""hi""\nthere"')).toEqual([
            ['a', 'b'],
            ['x, y', 'say "hi"\nthere'],
        ]);
    });

    it('skips blank lines, a byte order mark and Windows line ends', () => {
        expect(parseCsv('﻿a,b\r\n\r\n1,2\r\n')).toEqual([
            ['a', 'b'],
            ['1', '2'],
        ]);
    });

    it('answers nothing for empty text', () => {
        expect(parseCsv('  \n')).toEqual([]);
    });
});
