import { CheckIcon, CopyIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { FormDialog } from '@/components/form-dialog';
import type { IntegrationClientWithSecret } from '@/modules/identity-access/access';

function CopyField({
    id,
    label,
    value,
}: {
    id: string;
    label: string;
    value: string;
}) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            // Clipboard access can be refused; the value is selectable in the field.
        }
    };

    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <div className="flex gap-2">
                <Input
                    id={id}
                    readOnly
                    value={value}
                    className="font-mono text-xs"
                    onFocus={(event) => event.currentTarget.select()}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={t('integrations.secret.copy', { name: label })}
                    onClick={copy}
                >
                    {copied ? (
                        <CheckIcon aria-hidden />
                    ) : (
                        <CopyIcon aria-hidden />
                    )}
                </Button>
            </div>
        </Field>
    );
}

/**
 * Shows the secret of a new or rotated integration client (IA-FR-06). It is shown once: only a hash is
 * kept, so closing this dialog is the last time it can be read.
 */
export function SecretDialog({
    client,
    rotated,
    onClose,
}: {
    /** The client that was just registered or rotated; none keeps the dialog closed. */
    client: IntegrationClientWithSecret | null;
    rotated: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={client !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={
                rotated
                    ? t('integrations.secret.rotatedTitle')
                    : t('integrations.secret.createdTitle')
            }
            description={t('integrations.secret.warning')}
        >
            {client && (
                <div className="flex flex-col gap-4">
                    <Alert>
                        <AlertDescription>
                            {t('integrations.secret.warning')}
                        </AlertDescription>
                    </Alert>
                    <CopyField
                        id="integration-client-id"
                        label={t('integrations.secret.clientId')}
                        value={client.client_id}
                    />
                    <CopyField
                        id="integration-client-secret"
                        label={t('integrations.secret.clientSecret')}
                        value={client.client_secret}
                    />
                    <DialogFooter>
                        <Button type="button" onClick={onClose}>
                            {t('integrations.secret.done')}
                        </Button>
                    </DialogFooter>
                </div>
            )}
        </FormDialog>
    );
}
