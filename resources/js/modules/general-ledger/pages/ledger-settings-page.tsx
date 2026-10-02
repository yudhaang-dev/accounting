import { useTranslation } from 'react-i18next';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { AccountRolesSection } from '@/modules/general-ledger/components/account-roles-section';
import { LedgerPolicySection } from '@/modules/general-ledger/components/ledger-policy-form';

/**
 * Settings of the ledger: the account roles that closing and revaluation post to (GL-FR-10), and the
 * ledger policy for soft-closed periods, control accounts and revaluation. Both are versioned data.
 */
export function LedgerSettingsPage({
    companyId,
    canManageRoles,
    canManagePolicy,
}: {
    companyId: string;
    canManageRoles: boolean;
    canManagePolicy: boolean;
}) {
    const { t } = useTranslation();

    useDocumentTitle(t('ledgerSettings.pageTitle'));

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('ledgerSettings.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('ledgerSettings.description')}
                </p>
            </div>

            <AccountRolesSection
                companyId={companyId}
                canManage={canManageRoles}
            />
            {canManagePolicy && <LedgerPolicySection companyId={companyId} />}
        </>
    );
}
