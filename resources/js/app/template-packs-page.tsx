import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PackResult, TemplatePackStep } from '@/app/template-pack-step';
import {
    templatePacksQuery,
    type InstallResult,
    type Pack,
} from '@/app/template-packs';
import { FormAlert } from '@/components/form-alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';

/**
 * Template packs of a running company (X-AC-06, ADR-0019): add-on packs and packs for another business
 * type only add what the company lacks; what it has is skipped and a clash is reported, never overwritten.
 */
export function TemplatePacksPage({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const packs = useQuery(templatePacksQuery(companyId));
    const [installed, setInstalled] = useState<{
        result: InstallResult;
        pack: Pack;
    } | null>(null);

    useDocumentTitle(t('templatePacks.pageTitle'));

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('templatePacks.title')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('templatePacks.description')}
                </p>
            </div>

            <div className="max-w-3xl">
                {packs.isError ? (
                    <FormAlert>{genericErrorMessage(packs.error, t)}</FormAlert>
                ) : packs.data === undefined ? (
                    <Skeleton className="h-24 w-full" />
                ) : installed ? (
                    <div className="flex flex-col gap-4">
                        <PackResult
                            result={installed.result}
                            pack={installed.pack}
                        />
                        <Button
                            variant="outline"
                            className="w-auto self-start"
                            onClick={() => setInstalled(null)}
                        >
                            {t('templatePacks.another')}
                        </Button>
                    </div>
                ) : packs.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('templatePacks.empty')}
                    </p>
                ) : (
                    <TemplatePackStep
                        companyId={companyId}
                        packs={packs.data}
                        onInstalled={(result, pack) =>
                            setInstalled({ result, pack })
                        }
                    />
                )}
            </div>
        </>
    );
}
