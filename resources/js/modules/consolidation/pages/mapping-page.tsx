import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Badge } from '@/components/ui/badge';
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
import { Field, FieldLabel } from '@/components/ui/field';
import { todayIso } from '@/lib/date';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    groupAccountsQuery,
    groupCan,
    mappingQuery,
    memberAccountsQuery,
    perimeterQuery,
    saveMapping,
} from '@/modules/consolidation/groups';
import { useGroup } from '@/modules/consolidation/hooks/use-group';

const NONE = '__none';

/** Key of an account of a company in the draft mapping. */
const keyOf = (companyId: string, accountId: string) =>
    `${companyId}:${accountId}`;

/**
 * The mapping of company accounts to the chart of the group (CO-FR-04). It is saved as a whole and each save is
 * a new version with a date it takes effect from (P-04): the rows of every company are kept in the draft, so
 * choosing another company does not lose what was set for this one. An account can only go to a group account
 * of its own class, and only for a member whose assessment is approved.
 */
export function MappingPage({ groupId }: { groupId: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const group = useGroup(groupId);
    const mapping = useQuery(mappingQuery(groupId));
    const perimeter = useQuery(perimeterQuery(groupId));
    const groupAccounts = useQuery(groupAccountsQuery(groupId));
    const [companyId, setCompanyId] = useState<string | undefined>();
    const [draft, setDraft] = useState<Record<string, string> | null>(null);
    const [effectiveDate, setEffectiveDate] = useState(todayIso());
    const [formError, setFormError] = useState<string | null>(null);
    const canRun = groupCan(group, 'run');

    const members = (perimeter.data ?? []).filter(
        (member) => member.status === 'approved',
    );
    const selected = companyId ?? members[0]?.company_id;
    const accounts = useQuery({
        ...memberAccountsQuery(groupId, selected ?? ''),
        enabled: selected !== undefined,
    });

    // The draft starts from the version in force and is dropped when a newer one arrives.
    const version = mapping.data?.version;
    useEffect(() => {
        if (mapping.data !== undefined) {
            setDraft(
                Object.fromEntries(
                    mapping.data.mappings.map((row) => [
                        keyOf(row.company_id, row.account_id),
                        row.group_account_id,
                    ]),
                ),
            );
        }
    }, [version]); // eslint-disable-line react-hooks/exhaustive-deps

    const active = (groupAccounts.data ?? []).filter(
        (account) => account.is_active,
    );
    const dirty =
        mapping.data !== undefined &&
        draft !== null &&
        JSON.stringify(
            Object.entries(draft).sort(([a], [b]) => a.localeCompare(b)),
        ) !==
            JSON.stringify(
                mapping.data.mappings
                    .map((row) => [
                        keyOf(row.company_id, row.account_id),
                        row.group_account_id,
                    ])
                    .sort(([a], [b]) => a.localeCompare(b)),
            );

    const save = useMutation({
        mutationFn: () => {
            const rows = Object.entries(draft ?? {}).map(
                ([key, groupAccountId]) => {
                    const [company, account] = key.split(':');

                    return {
                        company_id: company,
                        account_id: account,
                        group_account_id: groupAccountId,
                    };
                },
            );

            return saveMapping(groupId, version ?? 0, rows, effectiveDate);
        },
        onSuccess: async (saved) => {
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'mapping'],
            });
            toast.success(
                t('consolidation.mapping.saved', { version: saved.version }),
            );
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await queryClient.invalidateQueries({
                    queryKey: ['groups', groupId, 'mapping'],
                });
                setFormError(t('consolidation.mapping.conflict'));

                return;
            }

            setFormError(
                isApiError(error) && error.status === 422
                    ? Object.values(error.errors).flat().join(' ')
                    : actionErrorMessage(error, t),
            );
        },
    });

    const choose = (account: string, value: string | null) => {
        if (selected === undefined) {
            return;
        }

        const next = { ...draft };

        if (value === null || value === NONE) {
            delete next[keyOf(selected, account)];
        } else {
            next[keyOf(selected, account)] = value;
        }

        setDraft(next);
    };

    const memberItems = members.map((member) => ({
        value: member.company_id,
        label: member.company_name,
    }));
    const mappedCount = Object.keys(draft ?? {}).length;

    return (
        <>
            <p className="max-w-2xl text-sm text-muted-foreground">
                {t('consolidation.mapping.description')}
            </p>

            {mapping.isError || perimeter.isError ? (
                <FormAlert>
                    {genericErrorMessage(mapping.error ?? perimeter.error, t)}
                </FormAlert>
            ) : mapping.data === undefined ||
              perimeter.data === undefined ||
              draft === null ? (
                <Skeleton className="h-48 w-full" />
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div className="flex flex-wrap items-end gap-3">
                            <Field className="w-64">
                                <FieldLabel htmlFor="mapping-company">
                                    {t('consolidation.mapping.company')}
                                </FieldLabel>
                                <Select
                                    value={selected ?? ''}
                                    items={memberItems}
                                    onValueChange={(value) =>
                                        setCompanyId(value ?? undefined)
                                    }
                                >
                                    <SelectTrigger id="mapping-company">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {memberItems.map((item) => (
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
                            </Field>
                            <Badge variant="secondary">
                                {version === 0
                                    ? t('consolidation.mapping.noVersion')
                                    : t('consolidation.mapping.version', {
                                          version,
                                      })}
                            </Badge>
                            <span className="text-sm text-muted-foreground">
                                {t('consolidation.mapping.count', {
                                    total: mappedCount,
                                })}
                            </span>
                        </div>

                        {canRun && (
                            <form
                                noValidate
                                className="flex flex-wrap items-end gap-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    setFormError(null);
                                    save.mutate();
                                }}
                            >
                                <Field>
                                    <FieldLabel id="mapping-date-label">
                                        {t(
                                            'consolidation.mapping.effectiveDate',
                                        )}
                                    </FieldLabel>
                                    <DatePicker
                                        labelledBy="mapping-date-label"
                                        value={effectiveDate}
                                        onChange={setEffectiveDate}
                                    />
                                </Field>
                                <SubmitButton
                                    pending={save.isPending}
                                    pendingLabel={t('common.saving')}
                                    disabled={!dirty || effectiveDate === ''}
                                >
                                    {t('consolidation.mapping.save')}
                                </SubmitButton>
                            </form>
                        )}
                    </div>

                    {formError && <FormAlert>{formError}</FormAlert>}

                    {members.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('consolidation.mapping.noMembers')}
                        </p>
                    ) : active.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('consolidation.mapping.noGroupAccounts')}
                        </p>
                    ) : accounts.isError ? (
                        <FormAlert>
                            {genericErrorMessage(accounts.error, t)}
                        </FormAlert>
                    ) : accounts.data === undefined ? (
                        <Skeleton className="h-48 w-full" />
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('consolidation.accounts.code')}
                                        </TableHead>
                                        <TableHead>
                                            {t('consolidation.accounts.name')}
                                        </TableHead>
                                        <TableHead>
                                            {t('consolidation.accounts.class')}
                                        </TableHead>
                                        <TableHead className="w-80">
                                            {t(
                                                'consolidation.mapping.groupAccount',
                                            )}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {accounts.data.map((account) => {
                                        // Only accounts of the same class are offered: a mismatch is refused by the API.
                                        const items = [
                                            {
                                                value: NONE,
                                                label: t(
                                                    'consolidation.mapping.notMapped',
                                                ),
                                            },
                                            ...active
                                                .filter(
                                                    (target) =>
                                                        target.class ===
                                                        account.class,
                                                )
                                                .map((target) => ({
                                                    value: target.id,
                                                    label: `${target.code} · ${target.name}`,
                                                })),
                                        ];
                                        const current = selected
                                            ? draft[keyOf(selected, account.id)]
                                            : undefined;

                                        return (
                                            <TableRow key={account.id}>
                                                <TableCell className="font-mono">
                                                    {account.code}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {account.name}
                                                </TableCell>
                                                <TableCell>
                                                    {t(
                                                        `consolidation.classes.${account.class as 'asset'}`,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={current ?? NONE}
                                                        items={items}
                                                        disabled={!canRun}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            choose(
                                                                account.id,
                                                                value,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            aria-label={t(
                                                                'consolidation.mapping.groupAccountFor',
                                                                {
                                                                    code: account.code,
                                                                },
                                                            )}
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectGroup>
                                                                {items.map(
                                                                    (item) => (
                                                                        <SelectItem
                                                                            key={
                                                                                item.value
                                                                            }
                                                                            value={
                                                                                item.value
                                                                            }
                                                                        >
                                                                            {
                                                                                item.label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectGroup>
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>
            )}
        </>
    );
}
