import { useQuery } from '@tanstack/react-query';
import { transactionTypesQuery } from '@/modules/transaction-entry/entries';

/**
 * The display name of a transaction type, given its key. Entries and journals carry only the stable
 * key; people read the name. Until the types have loaded (or for a key that no longer exists) the key
 * itself is shown, so nothing is ever blank.
 */
export function useTypeLabel(companyId: string): (key: string) => string {
    const types = useQuery(transactionTypesQuery(companyId));

    return (key) => types.data?.find((type) => type.key === key)?.label ?? key;
}
