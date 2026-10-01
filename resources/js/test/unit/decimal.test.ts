import { describe, expect, it } from 'vitest';
import {
    compareDecimals,
    differenceDecimals,
    formatDecimal,
    isDecimal,
    isPositive,
    sumDecimals,
} from '@/lib/decimal';

describe('decimal strings', () => {
    it('adds without floating point error', () => {
        expect(sumDecimals(['0.1', '0.2'])).toBe('0.3');
        expect(sumDecimals(['1000000.10', '2.9'])).toBe('1000003.00');
        expect(sumDecimals(['9007199254740993', '1'])).toBe('9007199254740994');
    });

    it('keeps the widest number of decimal places', () => {
        expect(sumDecimals(['1', '2.50'])).toBe('3.50');
        expect(sumDecimals(['1', '2'])).toBe('3');
    });

    it('treats blanks and invalid values as zero', () => {
        expect(sumDecimals(['', null, undefined, 'abc', '5'])).toBe('5');
        expect(sumDecimals([])).toBe('0');
    });

    it('compares and subtracts exactly', () => {
        expect(compareDecimals('0.10', '0.1')).toBe(0);
        expect(compareDecimals('2', '10')).toBe(-1);
        expect(compareDecimals('10.01', '10')).toBe(1);
        expect(differenceDecimals('100.00', '99.99')).toBe('0.01');
        expect(differenceDecimals('1', '3.5')).toBe('2.5');
    });

    it('recognises amounts a ledger would accept', () => {
        expect(isDecimal('12.50')).toBe(true);
        expect(isDecimal('-1')).toBe(false);
        expect(isDecimal('1,5')).toBe(false);
        expect(isDecimal('')).toBe(false);
        expect(isPositive('0.01')).toBe(true);
        expect(isPositive('0')).toBe(false);
    });

    it('groups digits per locale without rounding', () => {
        expect(formatDecimal('1234567.50', 'id')).toBe('1.234.567,50');
        expect(formatDecimal('1234567.50', 'en')).toBe('1,234,567.50');
        expect(formatDecimal('0.123456789', 'en')).toBe('0.123456789');
    });
});
