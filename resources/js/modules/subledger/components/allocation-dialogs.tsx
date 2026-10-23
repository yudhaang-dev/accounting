import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { MoneyInput } from '@/components/money-input';
import { SearchableSelect } from '@/components/searchable-select';
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
import { Textarea } from '@/components/ui/textarea';
import { useLastDefined } from '@/hooks/use-last-defined';
import { todayIso } from '@/lib/date';
import {
    compareDecimals,
    formatMoney,
    isDecimal,
    isPositive,
} from '@/lib/decimal';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    allocate,
    cancelAllocation,
    type Allocation,
} from '@/modules/subledger/allocations';
import {
    openItemsQuery,
    partyOptionsQuery,
    type OpenItem,
} from '@/modules/subledger/documents';

const refresh = (queryClient: ReturnType<typeof useQueryClient>, id: string) =>
    Promise.all(
        ['allocations', 'open-items', 'documents'].map((key) =>
            queryClient.invalidateQueries({
                queryKey: ['companies', id, key],
            }),
        ),
    );

function AllocateForm({
    companyId,
    onDone,
}: {
    companyId: string;
    onDone: () => void;
}) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [partyId, setPartyId] = useState('');
    const [debitId, setDebitId] = useState('');
    const [creditId, setCreditId] = useState('');
    const [amount, setAmount] = useState('');
    const [date, setDate] = useState(todayIso());
    const [formError, setFormError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    const parties = useQuery(partyOptionsQuery(companyId));
    const items = useQuery({
        ...openItemsQuery(companyId, {
            partyId,
            status: 'open',
            dueTo: '',
            page: 1,
            perPage: 200,
        }),
        enabled: partyId !== '',
    });
    const open = (items.data?.items ?? []).filter((item) =>
        isPositive(item.remaining_amount),
    );
    const debit = open.find((item) => item.id === debitId);
    const credit = open.find((item) => item.id === creditId);
    const debits = open.filter((item) => item.side === 'debit');
    // The two sides must share the control account and the currency.
    const credits = open.filter(
        (item) =>
            item.side === 'credit' &&
            (debit === undefined ||
                (item.control_role === debit.control_role &&
                    item.currency === debit.currency)),
    );
    const limit =
        debit && credit
            ? compareDecimals(debit.remaining_amount, credit.remaining_amount) <
              0
                ? debit.remaining_amount
                : credit.remaining_amount
            : undefined;

    const describe = (item: OpenItem) =>
        `${item.document_number} · ${formatMoney(item.remaining_amount, i18n.language)} ${item.currency}`;

    const save = useMutation({
        mutationFn: () =>
            allocate(companyId, {
                open_item_id: debitId,
                counterpart_open_item_id: creditId,
                amount,
                allocation_date: date,
            }),
        onSuccess: async () => {
            await refresh(queryClient, companyId);
            toast.success(t('allocations.created'));
            onDone();
        },
        onError: (error) => {
            if (isApiError(error) && error.status === 422) {
                const marked = Object.fromEntries(
                    Object.entries(error.errors).flatMap(([field, messages]) =>
                        messages[0] === undefined ? [] : [[field, messages[0]]],
                    ),
                );

                if (Object.keys(marked).length > 0) {
                    setFieldErrors(marked);

                    return;
                }
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    const submit = () => {
        setFormError(null);
        const errors: Record<string, string> = {};

        if (debitId === '') {
            errors.open_item_id = t('common.required');
        }

        if (creditId === '') {
            errors.counterpart_open_item_id = t('common.required');
        }

        if (!isDecimal(amount) || !isPositive(amount)) {
            errors.amount = t('allocations.amountInvalid');
        } else if (limit !== undefined && compareDecimals(amount, limit) > 0) {
            errors.amount = t('allocations.amountTooHigh', {
                max: formatMoney(limit, i18n.language),
            });
        }

        if (date === '') {
            errors.allocation_date = t('common.required');
        }

        setFieldErrors(errors);

        if (Object.keys(errors).length === 0) {
            save.mutate();
        }
    };

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field>
                    <FieldLabel htmlFor="alloc-party">
                        {t('allocations.party')}
                    </FieldLabel>
                    <SearchableSelect
                        id="alloc-party"
                        items={(parties.data ?? []).map((party) => ({
                            value: party.id,
                            label: party.name,
                        }))}
                        value={partyId}
                        onValueChange={(value) => {
                            setPartyId(value);
                            setDebitId('');
                            setCreditId('');
                            setAmount('');
                        }}
                        placeholder={t('allocations.chooseParty')}
                        emptyText={t('common.noMatch')}
                    />
                </Field>

                <Field
                    data-invalid={fieldErrors.open_item_id ? true : undefined}
                >
                    <FieldLabel htmlFor="alloc-debit">
                        {t('allocations.debitItem')}
                    </FieldLabel>
                    <SearchableSelect
                        id="alloc-debit"
                        items={debits.map((item) => ({
                            value: item.id,
                            label: describe(item),
                        }))}
                        value={debitId}
                        disabled={partyId === ''}
                        onValueChange={(value) => {
                            setDebitId(value);
                            setCreditId('');
                            setAmount('');
                        }}
                        placeholder={t('allocations.chooseItem')}
                        emptyText={t('allocations.noItems')}
                    />
                    <FieldDescription>
                        {t('allocations.debitHint')}
                    </FieldDescription>
                    {fieldErrors.open_item_id && (
                        <FieldError>{fieldErrors.open_item_id}</FieldError>
                    )}
                </Field>

                <Field
                    data-invalid={
                        fieldErrors.counterpart_open_item_id ? true : undefined
                    }
                >
                    <FieldLabel htmlFor="alloc-credit">
                        {t('allocations.creditItem')}
                    </FieldLabel>
                    <SearchableSelect
                        id="alloc-credit"
                        items={credits.map((item) => ({
                            value: item.id,
                            label: describe(item),
                        }))}
                        value={creditId}
                        disabled={debitId === ''}
                        onValueChange={(value) => {
                            setCreditId(value);
                            const chosen = open.find(
                                (item) => item.id === value,
                            );

                            if (debit && chosen) {
                                setAmount(
                                    compareDecimals(
                                        debit.remaining_amount,
                                        chosen.remaining_amount,
                                    ) < 0
                                        ? debit.remaining_amount
                                        : chosen.remaining_amount,
                                );
                            }
                        }}
                        placeholder={t('allocations.chooseItem')}
                        emptyText={t('allocations.noItems')}
                    />
                    <FieldDescription>
                        {t('allocations.creditHint')}
                    </FieldDescription>
                    {fieldErrors.counterpart_open_item_id && (
                        <FieldError>
                            {fieldErrors.counterpart_open_item_id}
                        </FieldError>
                    )}
                </Field>

                <Field data-invalid={fieldErrors.amount ? true : undefined}>
                    <FieldLabel htmlFor="alloc-amount">
                        {t('allocations.amount')}
                    </FieldLabel>
                    <MoneyInput
                        id="alloc-amount"
                        value={amount}
                        onChange={setAmount}
                        aria-invalid={fieldErrors.amount ? true : undefined}
                    />
                    {limit !== undefined && (
                        <FieldDescription>
                            {t('allocations.amountHint', {
                                max: formatMoney(limit, i18n.language),
                                currency: debit?.currency,
                            })}
                        </FieldDescription>
                    )}
                    {fieldErrors.amount && (
                        <FieldError>{fieldErrors.amount}</FieldError>
                    )}
                </Field>

                <Field
                    data-invalid={
                        fieldErrors.allocation_date ? true : undefined
                    }
                >
                    <FieldLabel id="alloc-date-label">
                        {t('allocations.date')}
                    </FieldLabel>
                    <DatePicker
                        labelledBy="alloc-date-label"
                        value={date}
                        onChange={setDate}
                    />
                    {fieldErrors.allocation_date && (
                        <FieldError>{fieldErrors.allocation_date}</FieldError>
                    )}
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
                        {t('allocations.submit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Settles an amount of a debit item against a credit item of the same party (SL-FR-05). */
export function AllocateDialog({
    companyId,
    open,
    onOpenChange,
}: {
    companyId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('allocations.createTitle')}
            description={t('allocations.createDescription')}
        >
            <AllocateForm
                companyId={companyId}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}

function CancelForm({
    companyId,
    allocation,
    onDone,
}: {
    companyId: string;
    allocation: Allocation;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [invalid, setInvalid] = useState(false);

    const cancel = useMutation({
        mutationFn: () =>
            cancelAllocation(companyId, allocation.id, reason.trim()),
        onSuccess: async () => {
            await refresh(queryClient, companyId);
            toast.success(t('allocations.cancelled'));
            onDone();
        },
        onError: (failure) => setError(actionErrorMessage(failure, t)),
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setError(null);

                if (reason.trim() === '') {
                    setInvalid(true);

                    return;
                }

                setInvalid(false);
                cancel.mutate();
            }}
        >
            <FieldGroup>
                {error && <FormAlert>{error}</FormAlert>}
                <Field data-invalid={invalid ? true : undefined}>
                    <FieldLabel htmlFor="cancel-reason">
                        {t('allocations.reason')}
                    </FieldLabel>
                    <Textarea
                        id="cancel-reason"
                        rows={3}
                        maxLength={500}
                        autoFocus
                        value={reason}
                        aria-invalid={invalid ? true : undefined}
                        onChange={(event) => setReason(event.target.value)}
                    />
                    {invalid && <FieldError>{t('common.required')}</FieldError>}
                </Field>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={cancel.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('allocations.cancelSubmit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Cancels an active allocation; the reason is mandatory and audited (SL-FR-05). */
export function CancelAllocationDialog({
    companyId,
    allocation,
    onClose,
}: {
    companyId: string;
    allocation: Allocation | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const shown = useLastDefined(allocation);

    return (
        <FormDialog
            open={allocation !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={t('allocations.cancelTitle')}
            description={t('allocations.cancelDescription')}
        >
            {shown && (
                <CancelForm
                    key={shown.id}
                    companyId={companyId}
                    allocation={shown}
                    onDone={onClose}
                />
            )}
        </FormDialog>
    );
}
