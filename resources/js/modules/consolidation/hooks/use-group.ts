import { useQuery } from '@tanstack/react-query';
import { groupsQuery, type Group } from '@/modules/consolidation/groups';

/**
 * The group from the user's list, with what the user may do on it. The list is what the API says the
 * user holds a grant on, so a group that is not in it is not theirs to open.
 */
export function useGroup(groupId: string): Group | undefined {
    return useQuery(groupsQuery).data?.find((group) => group.id === groupId);
}
