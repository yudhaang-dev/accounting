import { useSuspenseQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { EntryForm } from '@/modules/transaction-entry/components/entry-form';
import { entryQuery } from '@/modules/transaction-entry/entries';

function EditEntry({
    companyId,
    entryId,
    canManageTypes,
}: {
    companyId: string;
    entryId: string;
    canManageTypes: boolean;
}) {
    const { t } = useTranslation();
    const { data: entry } = useSuspenseQuery(entryQuery(companyId, entryId));

    useDocumentTitle(t('entries.form.editTitle', { number: entry.number }));

    return (
        <Card>
            <CardHeader>
                <CardTitle role="heading" aria-level={1}>
                    {t('entries.form.editTitle', { number: entry.number })}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {entry.status === 'draft' ? (
                    <EntryForm
                        companyId={companyId}
                        entry={entry}
                        canManageTypes={canManageTypes}
                    />
                ) : (
                    <div className="flex flex-col items-start gap-3">
                        <FormAlert>{t('entries.form.notEditable')}</FormAlert>
                        <Button
                            variant="outline"
                            render={
                                <Link
                                    to="/w/$companyId/entries/$entryId"
                                    params={{ companyId, entryId }}
                                />
                            }
                        >
                            {t('entries.detail.back')}
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/** New draft (no `entryId`) or the edit of an existing draft. */
export function EntryFormPage({
    companyId,
    entryId,
    canManageTypes,
}: {
    companyId: string;
    entryId?: string;
    canManageTypes: boolean;
}) {
    const { t } = useTranslation();

    useDocumentTitle(t('entries.form.newTitle'));

    if (entryId) {
        return (
            <EditEntry
                companyId={companyId}
                entryId={entryId}
                canManageTypes={canManageTypes}
            />
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle role="heading" aria-level={1}>
                    {t('entries.form.newTitle')}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <EntryForm
                    companyId={companyId}
                    canManageTypes={canManageTypes}
                />
            </CardContent>
        </Card>
    );
}
