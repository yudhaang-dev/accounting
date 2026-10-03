import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { approvalPolicyQuery } from '@/modules/transaction-entry/approval-policy';
import { ApprovalPolicyForm } from '@/modules/transaction-entry/components/approval-policy-form';

/**
 * Who must approve which entries (TE-FR-09, TE-AC-05): rules by transaction type, source and amount, the
 * stages each needs, separation of duties, and whether an approved entry posts by itself. Saving makes
 * a new version of the policy and is audited.
 */
export function ApprovalPolicyPage({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const policy = useQuery(approvalPolicyQuery(companyId));

    useDocumentTitle(t('approvalPolicy.pageTitle'));

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('approvalPolicy.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {policy.data?.version
                        ? t('approvalPolicy.descriptionVersion', {
                              version: policy.data.version,
                          })
                        : t('approvalPolicy.description')}
                </p>
            </div>

            {policy.isError ? (
                <FormAlert>{genericErrorMessage(policy.error, t)}</FormAlert>
            ) : policy.data === undefined ? (
                <Skeleton className="h-64 w-full" />
            ) : (
                <Card>
                    <CardContent>
                        <ApprovalPolicyForm
                            companyId={companyId}
                            policy={policy.data}
                        />
                    </CardContent>
                </Card>
            )}
        </>
    );
}
