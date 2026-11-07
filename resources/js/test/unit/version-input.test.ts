import { describe, expect, it } from 'vitest';
import {
    valuesOfVersion,
    versionInputOf,
} from '@/modules/transaction-entry/components/version-editor';
import type { TypeVersion } from '@/modules/transaction-entry/transaction-types';

const version = {
    version: 2,
    status: 'draft',
    mode: 'structured',
    fields: [
        { name: 'total', type: 'amount', label: 'Total', required: true },
        {
            name: 'revenue',
            type: 'account',
            label: 'Pendapatan',
            required: true,
            account_classes: ['income'],
        },
        { name: 'other', type: 'account', label: 'Lain', required: true },
    ],
    posting_rules: [],
} as unknown as TypeVersion;

describe('the field definitions of a version in the designer (TE-FR-04)', () => {
    it('keeps the account classes of an account field when the version is edited and saved again', () => {
        const input = versionInputOf(valuesOfVersion(version));

        expect(input.fields?.[1]).toMatchObject({
            name: 'revenue',
            account_classes: ['income'],
        });
    });

    it('sends no classes for a field that has none, which means every class', () => {
        const input = versionInputOf(valuesOfVersion(version));

        expect(input.fields?.[2]).not.toHaveProperty('account_classes');
        expect(input.fields?.[0]).not.toHaveProperty('account_classes');
    });
});
