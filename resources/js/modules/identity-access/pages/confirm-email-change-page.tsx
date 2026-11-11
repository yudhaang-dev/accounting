import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { accountApi } from '@/modules/identity-access/account';
import { refreshMe } from '@/modules/identity-access/session';

/**
 * The link of the e-mail sent to a new sign-in address (IA-FR-04). The token is single use, so the call is
 * made once when the page opens, never again on a re-render.
 */
export function ConfirmEmailChangePage({ token }: { token?: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const started = useRef(false);

    useDocumentTitle(t('account.confirmEmail.pageTitle'));

    const confirm = useMutation({
        mutationFn: accountApi.confirmEmailChange,
        onSuccess: () => refreshMe(queryClient),
    });

    useEffect(() => {
        if (token && !started.current) {
            started.current = true;
            confirm.mutate(token);
        }
    }, [token, confirm]);

    const failed = !token || confirm.isError;

    return (
        <div className="mx-auto flex max-w-md flex-col gap-4 py-10">
            <h1 className="text-xl font-semibold tracking-tight">
                {t('account.confirmEmail.title')}
            </h1>
            {failed ? (
                <Alert variant="destructive" role="alert">
                    <AlertDescription>
                        {token &&
                        isApiError(confirm.error) &&
                        confirm.error.status !== 422
                            ? actionErrorMessage(confirm.error, t)
                            : t('account.confirmEmail.invalid')}
                    </AlertDescription>
                </Alert>
            ) : confirm.isSuccess ? (
                <Alert role="status">
                    <AlertDescription>
                        {t('account.confirmEmail.done', {
                            email: confirm.data.email,
                        })}
                    </AlertDescription>
                </Alert>
            ) : (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner aria-hidden />
                    {t('account.confirmEmail.confirming')}
                </p>
            )}
            <Button
                render={<Link to="/account" />}
                variant="outline"
                className="w-fit"
            >
                {t('account.confirmEmail.toAccount')}
            </Button>
        </div>
    );
}
