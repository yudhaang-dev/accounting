import type { TFunction } from 'i18next';
import type {
    FieldDef,
    PostingRule,
} from '@/modules/transaction-entry/transaction-types';

/**
 * One posting rule as a sentence a reviewer can read: which side, which value, which account.
 * Used wherever rules are shown before someone relies on them (template review, version summary).
 */
export function postingRuleText(
    t: TFunction,
    rule: PostingRule,
    fields: FieldDef[],
    accountName?: (id: string) => string | undefined,
): string {
    if (rule.type === 'fx_difference') {
        return t('transactionTypes.rule.fxDifference');
    }

    const label = (name: string | null | undefined) =>
        fields.find((field) => field.name === name)?.label ?? name ?? '';
    const account = rule.account;
    const target =
        account?.source === 'field'
            ? t('transactionTypes.rule.accountFromField', {
                  field: label(account.field),
              })
            : (accountName?.(account?.account_id ?? '') ??
              t('transactionTypes.rule.fixedAccount'));

    return t('transactionTypes.rule.amount', {
        side: t(`transactionTypes.side.${rule.side ?? 'debit'}`),
        amount: label(rule.amount_field),
        account: target,
    });
}
