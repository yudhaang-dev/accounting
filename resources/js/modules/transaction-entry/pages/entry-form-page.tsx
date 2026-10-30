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
    canUseDimensions,
}: {
    companyId: string;
    entryId: string;
    canManageTypes: boolean;
    canUseDimensions: boolean;
}) {
    const { t } = useTranslation();
    const { data: entry } = useSuspenseQuery(entryQuery(companyId, entryId));

    useDocumentTitle(
        t('entries.form.editTitle', {
            number: entry.number ?? t('entries.unnumbered'),
        }),
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle role="heading" aria-level={1}>
                    {t('entries.form.editTitle', {
                        number: entry.number ?? t('entries.unnumbered'),
                    })}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {entry.status === 'draft' ? (
                    <EntryForm
                        companyId={companyId}
                        entry={entry}
                        canManageTypes={canManageTypes}
                        canUseDimensions={canUseDimensions}
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

function ReplaceEntry({
    companyId,
    replacesId,
    canManageTypes,
    canUseDimensions,
}: {
    companyId: string;
    replacesId: string;
    canManageTypes: boolean;
    canUseDimensions: boolean;
}) {
    const { t } = useTranslation();
    const { data: replaces } = useSuspenseQuery(
        entryQuery(companyId, replacesId),
    );

    useDocumentTitle(
        t('entries.form.replaceTitle', { number: replaces.number }),
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle role="heading" aria-level={1}>
                    {t('entries.form.replaceTitle', {
                        number: replaces.number,
                    })}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {replaces.status === 'posted' ? (
                    <EntryForm
                        companyId={companyId}
                        replaces={replaces}
                        canManageTypes={canManageTypes}
                        canUseDimensions={canUseDimensions}
                    />
                ) : (
                    <FormAlert>{t('entries.form.notReplaceable')}</FormAlert>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * New draft (no `entryId`), the edit of an existing draft, or a new entry that replaces a posted one
 * (`replacesId`, TE-FR-11) and starts as a copy of it.
 */
export function EntryFormPage({
    companyId,
    entryId,
    replacesId,
    typeKey,
    canManageTypes,
    canUseDimensions,
}: {
    companyId: string;
    entryId?: string;
    replacesId?: string;
    /** The type chosen in the catalog for a new entry. */
    typeKey?: string;
    canManageTypes: boolean;
    canUseDimensions: boolean;
}) {
    const { t } = useTranslation();

    useDocumentTitle(t('entries.form.newTitle'));

    if (entryId) {
        return (
            <EditEntry
                companyId={companyId}
                entryId={entryId}
                canManageTypes={canManageTypes}
                canUseDimensions={canUseDimensions}
            />
        );
    }

    if (replacesId) {
        return (
            <ReplaceEntry
                companyId={companyId}
                replacesId={replacesId}
                canManageTypes={canManageTypes}
                canUseDimensions={canUseDimensions}
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
                    typeKey={typeKey}
                    canManageTypes={canManageTypes}
                    canUseDimensions={canUseDimensions}
                />
            </CardContent>
        </Card>
    );
}
