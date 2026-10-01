import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { authApi } from '@/modules/identity-access/auth-api';
import { isApiError } from '@/lib/http';
import { clearMe } from '@/modules/identity-access/session';

/**
 * Signs the user out and returns to the login screen with a confirmation toast. If the session is
 * already gone (401/419) the result is the same, so that is treated as success; any cached data of
 * the previous user is dropped either way.
 */
export function useSignOut() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    return useMutation({
        mutationFn: async () => {
            try {
                await authApi.logout();
            } catch (error) {
                if (!isApiError(error) || ![401, 419].includes(error.status)) {
                    throw error;
                }
            }
        },
        onSuccess: async () => {
            queryClient.clear();
            clearMe(queryClient);
            toast.success(t('session.signedOut'));
            await navigate({ to: '/login', search: {} });
        },
        onError: () => {
            toast.error(t('common.unexpected'));
        },
    });
}
