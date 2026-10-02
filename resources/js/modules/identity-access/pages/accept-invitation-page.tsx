import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { actionErrorMessage } from '@/lib/errors';
import { acceptInvitation } from '@/modules/identity-access/access';
import { workspacesQuery } from '@/modules/identity-access/workspaces';

/**
 * Where the link of an invitation e-mail lands (IA-FR-01). Joining is an explicit click rather than
 * something the page does on load, so a link preview or a refresh cannot accept on someone's behalf.
 * The API accepts only a signed-in user whose verified e-mail is the invited address.
 */
export function AcceptInvitationPage({ token }: { token: string | undefined }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [error, setError] = useState<string | null>(null);

    useDocumentTitle(t('invitation.pageTitle'));

    const accept = useMutation({
        mutationFn: () => acceptInvitation(token ?? ''),
        onSuccess: async (workspace) => {
            // The new workspace must be in the list before its address is opened.
            await queryClient.fetchQuery({ ...workspacesQuery, staleTime: 0 });
            await navigate({
                to: '/w/$companyId',
                params: { companyId: workspace.company_id },
            });
        },
        onError: (failure) => setError(actionErrorMessage(failure, t)),
    });

    return (
        <div className="mx-auto flex w-full max-w-md flex-col gap-4 pt-10">
            <Card>
                <CardHeader>
                    <CardTitle>{t('invitation.title')}</CardTitle>
                    <CardDescription>
                        {token
                            ? t('invitation.description')
                            : t('invitation.missingToken')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    {error && <FormAlert>{error}</FormAlert>}
                    {token ? (
                        <SubmitButton
                            pending={accept.isPending}
                            pendingLabel={t('invitation.accepting')}
                            onClick={() => {
                                setError(null);
                                accept.mutate();
                            }}
                        >
                            {t('invitation.accept')}
                        </SubmitButton>
                    ) : (
                        <Button
                            variant="outline"
                            onClick={() => navigate({ to: '/' })}
                        >
                            {t('invitation.home')}
                        </Button>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
