import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PencilIcon, PlusIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatIsoDate, todayIso } from '@/lib/date';
import { formatDecimal } from '@/lib/decimal';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    GroupRateDialog,
    RateTypeDialog,
} from '@/modules/consolidation/components/translation-dialogs';
import {
    groupAccountsQuery,
    groupCan,
    groupRatesQuery,
    rateTypesQuery,
    saveTranslationPolicy,
    translationPolicyQuery,
    type RateType,
} from '@/modules/consolidation/groups';
import { useGroup } from '@/modules/consolidation/hooks/use-group';

type Draft = {
    closing: string;
    average: string;
    historical: string;
    difference: string;
};

const EMPTY: Draft = {
    closing: '',
    average: '',
    historical: '',
    difference: '',
};

/**
 * How the accounts of an entity whose currency is not the presentation currency are translated (CO-FR-06): the
 * policy, the rate types it names, and the rates. The policy is saved as a whole and each save is a new
 * version with a date it takes effect from (P-04).
 */
export function TranslationPage({ groupId }: { groupId: string }) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const group = useGroup(groupId);
    const canRun = groupCan(group, 'run');
    const policy = useQuery(translationPolicyQuery(groupId));
    const types = useQuery(rateTypesQuery(groupId));
    const accounts = useQuery(groupAccountsQuery(groupId));
    const [typeFilter, setTypeFilter] = useState('');
    const [showAll, setShowAll] = useState(false);
    const rates = useQuery(
        groupRatesQuery(groupId, {
            rate_type_id: typeFilter === '' ? undefined : typeFilter,
            include_superseded: showAll || undefined,
        }),
    );
    const [typeDialog, setTypeDialog] = useState<{ type?: RateType } | null>(
        null,
    );
    const [rateDialog, setRateDialog] = useState(false);
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [effectiveDate, setEffectiveDate] = useState(todayIso());
    const [formError, setFormError] = useState<string | null>(null);

    const version = policy.data?.version;
    useEffect(() => {
        if (policy.data !== undefined) {
            setDraft({
                closing: policy.data.closing_rate_type_id ?? '',
                average: policy.data.average_rate_type_id ?? '',
                historical: policy.data.historical_rate_type_id ?? '',
                difference: policy.data.difference_account_id ?? '',
            });
        }
    }, [version]); // eslint-disable-line react-hooks/exhaustive-deps

    const activeTypes = (types.data ?? []).filter((type) => type.is_active);
    const typeItems = activeTypes.map((type) => ({
        value: type.id,
        label: type.name,
    }));
    const equity = (accounts.data ?? []).filter(
        (account) => account.class === 'equity' && account.is_active,
    );
    const equityItems = equity.map((account) => ({
        value: account.id,
        label: `${account.code} · ${account.name}`,
    }));
    const complete =
        draft.closing !== '' &&
        draft.average !== '' &&
        draft.historical !== '' &&
        draft.difference !== '';
    const dirty =
        policy.data !== undefined &&
        (draft.closing !== (policy.data.closing_rate_type_id ?? '') ||
            draft.average !== (policy.data.average_rate_type_id ?? '') ||
            draft.historical !== (policy.data.historical_rate_type_id ?? '') ||
            draft.difference !== (policy.data.difference_account_id ?? ''));

    const save = useMutation({
        mutationFn: () =>
            saveTranslationPolicy(groupId, {
                lock_version: version ?? 0,
                effective_date: effectiveDate,
                closing_rate_type_id: draft.closing,
                average_rate_type_id: draft.average,
                historical_rate_type_id: draft.historical,
                difference_account_id: draft.difference,
            }),
        onSuccess: async (saved) => {
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'translation-policy'],
            });
            toast.success(
                t('consolidation.translation.policy.saved', {
                    version: saved.version,
                }),
            );
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await queryClient.invalidateQueries({
                    queryKey: ['groups', groupId, 'translation-policy'],
                });
                setFormError(t('consolidation.translation.policy.conflict'));

                return;
            }

            setFormError(
                isApiError(error) && error.status === 422
                    ? Object.values(error.errors).flat().join(' ')
                    : actionErrorMessage(error, t),
            );
        },
    });

    const typeName = (id: string) =>
        types.data?.find((type) => type.id === id)?.name ?? id;

    const slots: {
        key: keyof Draft;
        label: string;
        hint: string;
        items: { value: string; label: string }[];
    }[] = [
        {
            key: 'closing',
            label: t('consolidation.translation.policy.closing'),
            hint: t('consolidation.translation.policy.closingHint'),
            items: typeItems,
        },
        {
            key: 'average',
            label: t('consolidation.translation.policy.average'),
            hint: t('consolidation.translation.policy.averageHint'),
            items: typeItems,
        },
        {
            key: 'historical',
            label: t('consolidation.translation.policy.historical'),
            hint: t('consolidation.translation.policy.historicalHint'),
            items: typeItems,
        },
        {
            key: 'difference',
            label: t('consolidation.translation.policy.difference'),
            hint: t('consolidation.translation.policy.differenceHint'),
            items: equityItems,
        },
    ];

    return (
        <>
            <p className="max-w-2xl text-sm text-muted-foreground">
                {t('consolidation.translation.description')}
            </p>

            <section className="flex flex-col gap-4">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-base font-semibold">
                        {t('consolidation.translation.policy.title')}
                    </h2>
                    {policy.data !== undefined && (
                        <Badge variant="secondary">
                            {policy.data.version === 0
                                ? t('consolidation.translation.policy.none')
                                : t(
                                      'consolidation.translation.policy.version',
                                      {
                                          version: policy.data.version,
                                          date: formatIsoDate(
                                              policy.data.effective_date ?? '',
                                              i18n.language,
                                          ),
                                      },
                                  )}
                        </Badge>
                    )}
                </div>

                {policy.isError ? (
                    <FormAlert>
                        {genericErrorMessage(policy.error, t)}
                    </FormAlert>
                ) : policy.data === undefined ||
                  types.data === undefined ||
                  accounts.data === undefined ? (
                    <Skeleton className="h-40 w-full" />
                ) : (
                    <form
                        noValidate
                        className="flex flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setFormError(null);
                            save.mutate();
                        }}
                    >
                        {formError && <FormAlert>{formError}</FormAlert>}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {slots.map((slot) => (
                                <Field key={slot.key}>
                                    <FieldLabel htmlFor={`policy-${slot.key}`}>
                                        {slot.label}
                                    </FieldLabel>
                                    <Select
                                        value={draft[slot.key]}
                                        items={slot.items}
                                        disabled={!canRun}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                [slot.key]: value ?? '',
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id={`policy-${slot.key}`}
                                        >
                                            <SelectValue
                                                placeholder={t(
                                                    'consolidation.translation.policy.choose',
                                                )}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {slot.items.map((item) => (
                                                    <SelectItem
                                                        key={item.value}
                                                        value={item.value}
                                                    >
                                                        {item.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <FieldDescription>
                                        {slot.hint}
                                    </FieldDescription>
                                </Field>
                            ))}
                        </div>

                        {canRun && (
                            <div className="flex flex-wrap items-end gap-3">
                                <Field className="w-auto">
                                    <FieldLabel id="policy-date-label">
                                        {t(
                                            'consolidation.translation.policy.effectiveDate',
                                        )}
                                    </FieldLabel>
                                    <DatePicker
                                        labelledBy="policy-date-label"
                                        value={effectiveDate}
                                        onChange={setEffectiveDate}
                                    />
                                </Field>
                                <SubmitButton
                                    pending={save.isPending}
                                    pendingLabel={t('common.saving')}
                                    disabled={
                                        !dirty ||
                                        !complete ||
                                        effectiveDate === ''
                                    }
                                >
                                    {t('consolidation.translation.policy.save')}
                                </SubmitButton>
                            </div>
                        )}
                    </form>
                )}
            </section>

            <section className="flex flex-col gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-base font-semibold">
                        {t('consolidation.translation.types.title')}
                    </h2>
                    {canRun && (
                        <Button
                            variant="outline"
                            onClick={() => setTypeDialog({})}
                        >
                            <PlusIcon data-icon="inline-start" />
                            {t('consolidation.translation.types.new')}
                        </Button>
                    )}
                </div>

                {types.isError ? (
                    <FormAlert>{genericErrorMessage(types.error, t)}</FormAlert>
                ) : types.data === undefined ? (
                    <Skeleton className="h-24 w-full" />
                ) : types.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('consolidation.translation.types.empty')}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t(
                                            'consolidation.translation.types.code',
                                        )}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'consolidation.translation.types.name',
                                        )}
                                    </TableHead>
                                    <TableHead>
                                        {t('consolidation.accounts.status')}
                                    </TableHead>
                                    <TableHead className="w-0" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {types.data.map((type) => (
                                    <TableRow key={type.id}>
                                        <TableCell className="font-mono">
                                            {type.code}
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {type.name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    type.is_active
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {t(
                                                    type.is_active
                                                        ? 'consolidation.accounts.activeStatus'
                                                        : 'consolidation.accounts.inactiveStatus',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {canRun && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    aria-label={t(
                                                        'common.actionsFor',
                                                        { name: type.name },
                                                    )}
                                                    onClick={() =>
                                                        setTypeDialog({ type })
                                                    }
                                                >
                                                    <PencilIcon data-icon="inline-start" />
                                                    {t(
                                                        'consolidation.accounts.edit',
                                                    )}
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </section>

            <section className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-wrap items-end gap-3">
                        <h2 className="text-base font-semibold">
                            {t('consolidation.translation.rates.title')}
                        </h2>
                        <Field className="w-56">
                            <FieldLabel
                                htmlFor="rates-type-filter"
                                className="sr-only"
                            >
                                {t('consolidation.translation.rates.type')}
                            </FieldLabel>
                            <Select
                                value={typeFilter}
                                items={[
                                    {
                                        value: '',
                                        label: t(
                                            'consolidation.translation.rates.allTypes',
                                        ),
                                    },
                                    ...(types.data ?? []).map((type) => ({
                                        value: type.id,
                                        label: type.name,
                                    })),
                                ]}
                                onValueChange={(value) =>
                                    setTypeFilter(value ?? '')
                                }
                            >
                                <SelectTrigger id="rates-type-filter">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value="">
                                            {t(
                                                'consolidation.translation.rates.allTypes',
                                            )}
                                        </SelectItem>
                                        {(types.data ?? []).map((type) => (
                                            <SelectItem
                                                key={type.id}
                                                value={type.id}
                                            >
                                                {type.name}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field orientation="horizontal" className="w-auto">
                            <Checkbox
                                id="rates-show-all"
                                checked={showAll}
                                onCheckedChange={(next) =>
                                    setShowAll(next === true)
                                }
                            />
                            <FieldLabel
                                htmlFor="rates-show-all"
                                className="font-normal"
                            >
                                {t('consolidation.translation.rates.showAll')}
                            </FieldLabel>
                        </Field>
                    </div>
                    {canRun && (
                        <Button
                            disabled={activeTypes.length === 0}
                            onClick={() => setRateDialog(true)}
                        >
                            <PlusIcon data-icon="inline-start" />
                            {t('consolidation.translation.rates.new')}
                        </Button>
                    )}
                </div>

                {rates.isError ? (
                    <FormAlert>{genericErrorMessage(rates.error, t)}</FormAlert>
                ) : rates.data === undefined ? (
                    <Skeleton className="h-24 w-full" />
                ) : rates.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('consolidation.translation.rates.empty')}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t(
                                            'consolidation.translation.rates.date',
                                        )}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'consolidation.translation.rates.type',
                                        )}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'consolidation.translation.rates.currency',
                                        )}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t(
                                            'consolidation.translation.rates.rate',
                                        )}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'consolidation.translation.rates.version',
                                        )}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rates.data.map((rate) => (
                                    <TableRow key={rate.id}>
                                        <TableCell>
                                            {formatIsoDate(
                                                rate.effective_date,
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {typeName(rate.rate_type_id)}
                                        </TableCell>
                                        <TableCell className="font-mono">
                                            {rate.currency}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatDecimal(
                                                rate.rate,
                                                i18n.language,
                                            )}
                                        </TableCell>
                                        <TableCell>{rate.version}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </section>

            <RateTypeDialog
                groupId={groupId}
                open={typeDialog !== null}
                type={typeDialog?.type}
                onOpenChange={(open) => {
                    if (!open) {
                        setTypeDialog(null);
                    }
                }}
            />
            <GroupRateDialog
                groupId={groupId}
                open={rateDialog}
                types={activeTypes}
                presentationCurrency={group?.presentation_currency ?? ''}
                onOpenChange={setRateDialog}
            />
        </>
    );
}
