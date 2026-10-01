import { describe, expect, it } from 'vitest';
import { formatCanonical, separatorsFor, typeAmount } from '@/lib/amount';
import { formatIsoDate, parseIsoDate, toIsoDate } from '@/lib/date';
import { slugify } from '@/lib/slug';

const id = separatorsFor('id');
const en = separatorsFor('en');

describe('typing an amount', () => {
    it('knows the separators of each language', () => {
        expect(id).toEqual({ group: '.', decimal: ',' });
        expect(en).toEqual({ group: ',', decimal: '.' });
    });

    it('groups thousands as the digits are typed', () => {
        expect(typeAmount('1', 1, id).text).toBe('1');
        expect(typeAmount('1500', 4, id).text).toBe('1.500');
        expect(typeAmount('1500000', 7, id).text).toBe('1.500.000');
        expect(typeAmount('1500000', 7, en).text).toBe('1,500,000');
    });

    it('keeps the canonical value free of separators', () => {
        expect(typeAmount('1.500.000,50', 12, id).canonical).toBe('1500000.50');
        expect(typeAmount('1,500,000.50', 12, en).canonical).toBe('1500000.50');
        expect(typeAmount('12', 2, id).canonical).toBe('12');
    });

    it('lets the decimal separator be typed before any decimal', () => {
        const typed = typeAmount('12,', 3, id);

        expect(typed.text).toBe('12,');
        expect(typed.canonical).toBe('12');
        expect(typeAmount(',5', 2, id)).toMatchObject({
            text: '0,5',
            canonical: '0.5',
        });
    });

    it('accepts a single decimal separator and limits the places', () => {
        expect(typeAmount('1,2,3', 5, id).text).toBe('1,23');
        expect(typeAmount('0,12345678', 10, id).canonical).toBe('0.123456');
    });

    it('drops everything that is not part of a number', () => {
        expect(typeAmount('abc', 3, id)).toMatchObject({
            text: '',
            canonical: '',
        });
        expect(typeAmount('-5', 2, id).canonical).toBe('5');
        expect(typeAmount('Rp 1.000', 8, id).canonical).toBe('1000');
    });

    it('drops leading zeros but keeps a lone zero', () => {
        expect(typeAmount('007', 3, id).canonical).toBe('7');
        expect(typeAmount('0', 1, id).canonical).toBe('0');
    });

    it('puts the caret after the digit just typed, past inserted separators', () => {
        // "1.50" + typing "0" before the end: raw "1.500", caret 5.
        expect(typeAmount('1.500', 5, id).caret).toBe(5);
        // 999 -> typing 9 at the end gives 9.999: the caret moves past the new separator.
        expect(typeAmount('9999', 4, id)).toMatchObject({
            text: '9.999',
            caret: 5,
        });
        // Typing in the middle of 1.234 (raw "1.2534", caret after the 5).
        expect(typeAmount('1.2534', 4, id)).toMatchObject({
            text: '12.534',
            caret: 4,
        });
    });

    it('shows a stored canonical value grouped, with its decimals', () => {
        expect(formatCanonical('1500000.50', id)).toBe('1.500.000,50');
        expect(formatCanonical('1500000.50', en)).toBe('1,500,000.50');
        expect(formatCanonical('0.1', id)).toBe('0,1');
        expect(formatCanonical('', id)).toBe('');
        expect(formatCanonical('abc', id)).toBe('');
    });
});

describe('calendar dates', () => {
    it('reads a date in the local zone, never the day before', () => {
        const date = parseIsoDate('2026-10-02');

        expect(date?.getFullYear()).toBe(2026);
        expect(date?.getMonth()).toBe(9);
        expect(date?.getDate()).toBe(2);
    });

    it('rejects what is not a real date', () => {
        expect(parseIsoDate('2026-02-30')).toBeUndefined();
        expect(parseIsoDate('2026-13-01')).toBeUndefined();
        expect(parseIsoDate('02/10/2026')).toBeUndefined();
        expect(parseIsoDate('')).toBeUndefined();
        expect(parseIsoDate(undefined)).toBeUndefined();
    });

    it('round-trips through the string form', () => {
        for (const value of [
            '2026-01-01',
            '2026-02-28',
            '2024-02-29',
            '2026-12-31',
        ]) {
            expect(toIsoDate(parseIsoDate(value) as Date)).toBe(value);
        }
    });

    it('writes the date out for the reader', () => {
        expect(formatIsoDate('2026-10-02', 'id')).toBe('2 Oktober 2026');
        expect(formatIsoDate('2026-10-02', 'en')).toBe('October 2, 2026');
        expect(formatIsoDate('nonsense', 'id')).toBe('nonsense');
    });
});

describe('typing an amount with stray characters', () => {
    it('keeps the caret right when an emoji is pasted before it', () => {
        // "💰12" is 4 UTF-16 units; the caret after the 2 is at 4.
        expect(typeAmount('💰12', 4, id)).toMatchObject({
            text: '12',
            canonical: '12',
            caret: 2,
        });
    });
});

describe('keys from names', () => {
    it('writes a name as a key', () => {
        expect(slugify('Jurnal Umum')).toBe('jurnal_umum');
        expect(slugify('  Jurnal   Penyesuaian -- Akhir!  ')).toBe(
            'jurnal_penyesuaian_akhir',
        );
    });

    it('folds accents and drops symbols', () => {
        expect(slugify('Café Résumé')).toBe('cafe_resume');
        expect(slugify('Kas & Bank (IDR)')).toBe('kas_bank_idr');
    });

    it('starts with a letter and stays within the length', () => {
        expect(slugify('2026 Penutup')).toBe('t_2026_penutup');
        expect(slugify('a'.repeat(100))).toHaveLength(64);
        expect(slugify('x'.repeat(63) + ' y')).toBe('x'.repeat(63));
    });

    it('is empty when there is nothing to write', () => {
        expect(slugify('')).toBe('');
        expect(slugify('!!!')).toBe('');
    });
});
