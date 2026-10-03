import {
    useMutation,
    useQueryClient,
    useSuspenseQuery,
} from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ArrowLeftIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    valuesOfVersion,
    versionInputOf,
    VersionEditor,
} from '@/modules/transaction-entry/components/version-editor';
import { VersionSummary } from '@/modules/transaction-entry/components/version-summary';
import {
    createVersion,
    publishVersion,
    retireVersion,
    transactionTypeQuery,
    type TypeVersion,
} from '@/modules/transaction-entry/transaction-types';

/** Starting point of the selection: the draft being worked on, else the newest version. */
function initialVersion(versions: TypeVersion[]): number | null {
    const sorted = [...versions].sort((a, b) => b.version - a.version);

    return (
        (sorted.find((version) => version.status === 'draft') ?? sorted[0])
            ?.version ?? null
    );
}

/**
 * One transaction type and its versions (TE-FR-02). A published version is a fixed package; a change is
 * a new draft version, edited here and published when it is ready. Retiring a version stops new entries
 * from using it and leaves the entries already made untouched.
 */
export function TransactionTypePage({
    companyId,
    typeId,
    canManage,
}: {
    companyId: string;
    typeId: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const { data: type } = useSuspenseQuery(
        transactionTypeQuery(companyId, typeId),
    );
    const [picked, setPicked] = useState<number | null>(null);
    const [confirming, setConfirming] = useState<'publish' | 'retire' | null>(
        null,
    );
    const [actionError, setActionError] = useState<string | null>(null);

    useDocumentTitle(
        t('transactionTypes.designer.title', { label: type.label }),
    );

    const versions = [...type.versions].sort((a, b) => b.version - a.version);
    const selected =
        versions.find((version) => version.version === picked) ??
        versions.find(
            (version) => version.version === initialVersion(versions),
        );

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'transaction-types'],
        });

    const draft = useMutation({
        mutationFn: () => {
            const latest = versions[0];

            return createVersion(
                companyId,
                type.id,
                latest
                    ? versionInputOf(valuesOfVersion(latest))
                    : { mode: 'free_lines', fields: [] },
            );
        },
        onSuccess: async (saved) => {
            await refresh();
            setPicked(Math.max(...saved.versions.map((v) => v.version)));
            toast.success(t('transactionTypes.designer.drafted'));
        },
        onError: (error) => setActionError(actionErrorMessage(error, t)),
    });

    const change = useMutation({
        mutationFn: (action: 'publish' | 'retire') =>
            action === 'publish'
                ? publishVersion(companyId, type.id, selected?.version ?? 0)
                : retireVersion(companyId, type.id, selected?.version ?? 0),
        onSuccess: async (_, action) => {
            await refresh();
            toast.success(t(`transactionTypes.designer.${action}Done`));
            setConfirming(null);
        },
        onError: (error) => {
            setConfirming(null);
            // Publishing says what is missing: no fields, no rules, a field a rule reuses without saying so.
            setActionError(
                isApiError(error) && error.status === 422
                    ? [error.message, ...Object.values(error.errors).flat()]
                          .filter(Boolean)
                          .join(' ')
                    : actionErrorMessage(error, t),
            );
        },
    });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <Button
                        variant="link"
                        size="sm"
                        className="h-auto w-fit p-0"
                        render={
                            <Link
                                to="/w/$companyId/transaction-types"
                                params={{ companyId }}
                            />
                        }
                    >
                        <ArrowLeftIcon data-icon="inline-start" />
                        {t('transactionTypes.designer.back')}
                    </Button>
                    <h1 className="text-xl font-semibold tracking-tight">
                        {type.label}
                    </h1>
                    <span className="font-mono text-xs text-muted-foreground">
                        {type.key}
                    </span>
                </div>
                {canManage && (
                    <Button
                        variant="outline"
                        disabled={draft.isPending}
                        onClick={() => {
                            setActionError(null);
                            draft.mutate();
                        }}
                    >
                        <PlusIcon data-icon="inline-start" />
                        {t('transactionTypes.designer.newVersion')}
                    </Button>
                )}
            </div>

            {actionError && <FormAlert>{actionError}</FormAlert>}

            <div className="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
                <nav aria-label={t('transactionTypes.designer.versions')}>
                    <ul className="flex flex-row gap-2 overflow-x-auto lg:flex-col">
                        {versions.map((version) => (
                            <li key={version.version}>
                                <Button
                                    variant={
                                        version.version === selected?.version
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    className="w-full justify-between"
                                    aria-current={
                                        version.version === selected?.version
                                            ? 'true'
                                            : undefined
                                    }
                                    onClick={() => setPicked(version.version)}
                                >
                                    <span>
                                        {t('transactionTypes.version', {
                                            version: version.version,
                                        })}
                                    </span>
                                    <Badge
                                        variant={
                                            version.status === 'published'
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {t(
                                            `transactionTypes.statuses.${version.status}`,
                                        )}
                                    </Badge>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </nav>

                {selected && (
                    <Card>
                        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                            <CardTitle>
                                {t('transactionTypes.designer.versionTitle', {
                                    version: selected.version,
                                })}
                            </CardTitle>
                            {canManage && selected.status === 'draft' && (
                                <Button
                                    disabled={change.isPending}
                                    onClick={() => {
                                        setActionError(null);
                                        setConfirming('publish');
                                    }}
                                >
                                    {t('transactionTypes.designer.publish')}
                                </Button>
                            )}
                            {canManage && selected.status === 'published' && (
                                <Button
                                    variant="outline"
                                    disabled={change.isPending}
                                    onClick={() => {
                                        setActionError(null);
                                        setConfirming('retire');
                                    }}
                                >
                                    {t('transactionTypes.designer.retire')}
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent>
                            {selected.status === 'draft' && canManage ? (
                                <VersionEditor
                                    key={`${type.id}-${selected.version}`}
                                    companyId={companyId}
                                    type={type}
                                    version={selected}
                                />
                            ) : (
                                <VersionSummary
                                    companyId={companyId}
                                    version={selected}
                                />
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>

            <ConfirmDialog
                open={confirming !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirming(null);
                    }
                }}
                title={t(
                    `transactionTypes.designer.confirm.${confirming ?? 'publish'}.title`,
                    { version: selected?.version },
                )}
                description={t(
                    `transactionTypes.designer.confirm.${confirming ?? 'publish'}.description`,
                )}
                confirmLabel={t(
                    `transactionTypes.designer.confirm.${confirming ?? 'publish'}.action`,
                )}
                destructive={confirming === 'retire'}
                pending={change.isPending}
                onConfirm={() => confirming && change.mutate(confirming)}
            />
        </>
    );
}
