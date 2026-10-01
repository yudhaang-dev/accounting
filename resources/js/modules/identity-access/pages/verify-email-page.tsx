import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { MailIcon } from 'lucide-react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { AuthLayout } from '@/components/auth-layout';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { FieldGroup } from '@/components/ui/field';
import { useCountdown } from '@/hooks/use-countdown';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { authApi } from '@/modules/identity-access/auth-api';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { refreshMe, type Me } from '@/modules/identity-access/session';
import { useSignOut } from '@/modules/identity-access/hooks/use-sign-out';

const RESEND_COOLDOWN_SECONDS = 60;

export function VerifyEmailPage({ me }: { me: Me }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const cooldown = useCountdown();
    const signOut = useSignOut();

    useDocumentTitle(t('verify.pageTitle'));

    // The link in the e-mail is usually opened in another tab: when the user comes back here,
    // check again and move on by itself instead of asking them to refresh.
    useEffect(() => {
        const recheck = async () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            const latest = await refreshMe(queryClient).catch(() => null);

            if (latest?.email_verified) {
                await navigate({ to: '/' });
            }
        };

        document.addEventListener('visibilitychange', recheck);
        window.addEventListener('focus', recheck);

        return () => {
            document.removeEventListener('visibilitychange', recheck);
            window.removeEventListener('focus', recheck);
        };
    }, [navigate, queryClient]);

    const resend = useMutation({
        mutationFn: authApi.resendVerification,
        onSuccess: () => {
            toast.success(t('verify.sent'));
            cooldown.start(RESEND_COOLDOWN_SECONDS);
        },
        onError: (error) => {
            if (isApiError(error) && error.isRateLimited) {
                cooldown.start(error.retryAfter ?? RESEND_COOLDOWN_SECONDS);

                return;
            }

            toast.error(genericErrorMessage(error, t));
        },
    });

    return (
        <AuthLayout
            title={t('verify.title')}
            description={t('verify.description', { email: me.email })}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    resend.mutate();
                }}
            >
                <FieldGroup>
                    <div
                        className="flex justify-center text-primary"
                        aria-hidden
                    >
                        <MailIcon className="size-10" />
                    </div>

                    <SubmitButton
                        pending={resend.isPending}
                        pendingLabel={t('forgot.submitting')}
                        disabled={cooldown.active}
                    >
                        {cooldown.active
                            ? t('verify.resendIn', {
                                  seconds: cooldown.remaining,
                              })
                            : t('verify.resend')}
                    </SubmitButton>

                    <Button
                        type="button"
                        variant="ghost"
                        className="w-full text-muted-foreground"
                        disabled={signOut.isPending}
                        onClick={() => signOut.mutate()}
                    >
                        {t('verify.signOut')}
                    </Button>
                </FieldGroup>
            </form>
        </AuthLayout>
    );
}
