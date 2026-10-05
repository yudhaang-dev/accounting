import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
    agingPolicyQuery,
    updateAgingPolicy,
    type AgingBasis,
    type AgingPolicy,
} from '@/modules/subledger/aging';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { FilterSelect } from '@/modules/subledger/components/filter-select';

/** Parses "30, 60, 90" into ascending whole days, or null when it is not that. */
export function parseBuckets(text: string): number[] | null {
    const parts = text
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part !== '');

    if (parts.length === 0 || parts.length > 12) {
        return null;
    }

    const days = parts.map((part) => (/^\d+$/.test(part) ? Number(part) : 0));

    return days.every(
        (day, index) =>
            day >= 1 && day <= 36500 && (index === 0 || day > days[index - 1]),
    )
        ? days
        : null;
}

function PolicyForm({
    companyId,
    policy,
    onDone,
}: {
    companyId: string;
    policy: AgingPolicy;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [basis, setBasis] = useState<AgingBasis>(policy.basis);
    const [buckets, setBuckets] = useState(policy.buckets.join(', '));
    const [invalid, setInvalid] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const refresh = () =>
        Promise.all([
            queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'aging-policy'],
            }),
            queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'reports', 'aging'],
            }),
        ]);

    const save = useMutation({
        mutationFn: (days: number[]) =>
            updateAgingPolicy(companyId, {
                basis,
                buckets: days,
                lock_version: policy.lock_version,
            }),
        onSuccess: async () => {
            await refresh();
            toast.success(t('aging.policySaved'));
            onDone();
        },
        onError: async (failure) => {
            if (isApiError(failure) && failure.code === 'VERSION_CONFLICT') {
                await refresh();
                setError(t('aging.policyConflict'));

                return;
            }

            setError(actionErrorMessage(failure, t));
        },
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setError(null);
                const days = parseBuckets(buckets);

                setInvalid(days === null);

                if (days !== null) {
                    save.mutate(days);
                }
            }}
        >
            <FieldGroup>
                {error && <FormAlert>{error}</FormAlert>}
                <Field>
                    <FieldLabel>{t('aging.basis')}</FieldLabel>
                    <FilterSelect
                        label={t('aging.basis')}
                        anyLabel={t('aging.basisDueDate')}
                        options={[
                            {
                                value: 'document_date',
                                label: t('aging.basisDocumentDate'),
                            },
                        ]}
                        value={
                            basis === 'document_date'
                                ? 'document_date'
                                : undefined
                        }
                        onChange={(value) => setBasis(value ?? 'due_date')}
                        className="w-full"
                    />
                </Field>
                <Field data-invalid={invalid ? true : undefined}>
                    <FieldLabel htmlFor="aging-buckets">
                        {t('aging.buckets')}
                    </FieldLabel>
                    <Input
                        id="aging-buckets"
                        value={buckets}
                        aria-invalid={invalid ? true : undefined}
                        onChange={(event) => setBuckets(event.target.value)}
                    />
                    <FieldDescription>{t('aging.bucketsHint')}</FieldDescription>
                    {invalid && <FieldError>{t('aging.bucketsInvalid')}</FieldError>}
                </Field>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('common.save')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** The buckets and the date aging counts from; a change applies to every report from now on (SL-FR-08). */
export function AgingPolicyDialog({
    companyId,
    open,
    onOpenChange,
}: {
    companyId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const policy = useQuery({ ...agingPolicyQuery(companyId), enabled: open });

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('aging.policyTitle')}
            description={t('aging.policyDescription')}
        >
            {policy.data ? (
                <PolicyForm
                    key={policy.data.lock_version}
                    companyId={companyId}
                    policy={policy.data}
                    onDone={() => onOpenChange(false)}
                />
            ) : (
                <Skeleton className="h-24 w-full" />
            )}
        </FormDialog>
    );
}
