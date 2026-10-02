import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlugIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useLastDefined } from '@/hooks/use-last-defined';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import {
    integrationClientsQuery,
    revokeIntegrationClient,
    rotateIntegrationSecret,
    type IntegrationClient,
    type IntegrationClientWithSecret,
} from '@/modules/identity-access/access';
import { IntegrationClientDialog } from '@/modules/identity-access/components/integration-client-dialog';
import { SecretDialog } from '@/modules/identity-access/components/secret-dialog';

/**
 * Integration clients of the company (IA-FR-06): the systems that call the API with their own
 * credentials. Each is tied to one `source_system`, a set of scopes and the transaction types it may
 * use. Its secret is shown once, on registering and on every rotation; revoking is final.
 */
export function IntegrationClientsPage({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [registering, setRegistering] = useState(false);
    const [editing, setEditing] = useState<IntegrationClient | null>(null);
    const [rotating, setRotating] = useState<IntegrationClient | null>(null);
    const [revoking, setRevoking] = useState<IntegrationClient | null>(null);
    const [secret, setSecret] = useState<{
        client: IntegrationClientWithSecret;
        rotated: boolean;
    } | null>(null);
    const askingRotate = useLastDefined(rotating);
    const askingRevoke = useLastDefined(revoking);

    useDocumentTitle(t('integrations.pageTitle'));

    const clients = useQuery(integrationClientsQuery(companyId));

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'integration-clients'],
        });

    const rotate = useMutation({
        mutationFn: (client: IntegrationClient) =>
            rotateIntegrationSecret(companyId, client.id),
        onSuccess: async (client) => {
            await refresh();
            setSecret({ client, rotated: true });
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setRotating(null),
    });

    const revoke = useMutation({
        mutationFn: (client: IntegrationClient) =>
            revokeIntegrationClient(companyId, client.id),
        onSuccess: async (client) => {
            await refresh();
            toast.success(t('integrations.revoked', { name: client.name }));
        },
        onError: (error) => toast.error(actionErrorMessage(error, t)),
        onSettled: () => setRevoking(null),
    });

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('integrations.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('integrations.description')}
                    </p>
                </div>
                <Button onClick={() => setRegistering(true)}>
                    <PlusIcon data-icon="inline-start" />
                    {t('integrations.register')}
                </Button>
            </div>

            {clients.isError ? (
                <FormAlert>{genericErrorMessage(clients.error, t)}</FormAlert>
            ) : clients.data === undefined ? (
                <Skeleton className="h-48 w-full" />
            ) : clients.data.length === 0 ? (
                <Empty className="max-w-xl flex-none border py-10">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <PlugIcon />
                        </EmptyMedia>
                        <EmptyTitle>{t('integrations.empty')}</EmptyTitle>
                        <EmptyDescription>
                            {t('integrations.emptyDescription')}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('integrations.name')}</TableHead>
                                <TableHead>
                                    {t('integrations.sourceSystem')}
                                </TableHead>
                                <TableHead>
                                    {t('integrations.scopesTitle')}
                                </TableHead>
                                <TableHead className="text-end">
                                    {t('integrations.typeKeys')}
                                </TableHead>
                                <TableHead>
                                    {t('integrations.status')}
                                </TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">
                                        {t('integrations.actions')}
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {clients.data.map((client) => {
                                const active = client.status === 'active';

                                return (
                                    <TableRow
                                        key={client.id}
                                        className={
                                            active
                                                ? undefined
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <TableCell className="font-medium">
                                            {client.name}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {client.source_system}
                                        </TableCell>
                                        <TableCell>
                                            <span className="flex flex-wrap gap-1">
                                                {client.scopes.map((scope) => (
                                                    <Badge
                                                        key={scope}
                                                        variant="secondary"
                                                        className="font-mono"
                                                    >
                                                        {scope}
                                                    </Badge>
                                                ))}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {
                                                client.transaction_type_keys
                                                    .length
                                            }
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {t(
                                                    active
                                                        ? 'integrations.active'
                                                        : 'integrations.revokedStatus',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-end">
                                            {active && (
                                                <RowActions label={client.name}>
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setEditing(client)
                                                        }
                                                    >
                                                        {t(
                                                            'integrations.editAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setRotating(client)
                                                        }
                                                    >
                                                        {t(
                                                            'integrations.rotateAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() =>
                                                            setRevoking(client)
                                                        }
                                                    >
                                                        {t(
                                                            'integrations.revokeAction',
                                                        )}
                                                    </DropdownMenuItem>
                                                </RowActions>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}

            <IntegrationClientDialog
                companyId={companyId}
                open={registering}
                onOpenChange={setRegistering}
                onCreated={(client) => setSecret({ client, rotated: false })}
            />
            <IntegrationClientDialog
                companyId={companyId}
                client={editing ?? undefined}
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
                onCreated={() => undefined}
            />
            <SecretDialog
                client={secret?.client ?? null}
                rotated={secret?.rotated ?? false}
                onClose={() => setSecret(null)}
            />
            <ConfirmDialog
                open={rotating !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRotating(null);
                    }
                }}
                title={t('confirm.rotateSecret.title', {
                    name: askingRotate?.name ?? '',
                })}
                description={t('confirm.rotateSecret.description')}
                confirmLabel={t('confirm.rotateSecret.action')}
                pending={rotate.isPending}
                destructive
                onConfirm={() => rotating && rotate.mutate(rotating)}
            />
            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRevoking(null);
                    }
                }}
                title={t('confirm.revokeClient.title', {
                    name: askingRevoke?.name ?? '',
                })}
                description={t('confirm.revokeClient.description')}
                confirmLabel={t('confirm.revokeClient.action')}
                pending={revoke.isPending}
                destructive
                onConfirm={() => revoking && revoke.mutate(revoking)}
            />
        </>
    );
}
