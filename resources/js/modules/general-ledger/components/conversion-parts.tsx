import { CheckIcon } from 'lucide-react';
import { useId, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { isApiError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { Account } from '@/modules/general-ledger/accounts';
import type { Problem } from '@/modules/general-ledger/conversion';

/** The steps of a conversion wizard, the ones behind ticked and the current one marked. */
export function ConversionSteps({
    steps,
    current,
}: {
    steps: string[];
    /** 1-based. */
    current: number;
}) {
    const { t } = useTranslation();

    return (
        <nav aria-label={t('conversion.stepsLabel')}>
            <ol className="flex flex-wrap items-center gap-x-4 gap-y-2">
                {steps.map((label, index) => {
                    const number = index + 1;
                    const state =
                        number < current
                            ? 'done'
                            : number === current
                              ? 'current'
                              : 'upcoming';

                    return (
                        <li
                            key={label}
                            aria-current={
                                state === 'current' ? 'step' : undefined
                            }
                            className="flex items-center gap-2 text-sm"
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium',
                                    state === 'done' &&
                                        'border-primary bg-primary text-primary-foreground',
                                    state === 'current' &&
                                        'border-primary text-foreground ring-2 ring-primary/30',
                                    state === 'upcoming' &&
                                        'text-muted-foreground',
                                )}
                            >
                                {state === 'done' ? (
                                    <CheckIcon className="size-3.5" />
                                ) : (
                                    number
                                )}
                            </span>
                            <span
                                className={
                                    state === 'upcoming'
                                        ? 'text-muted-foreground'
                                        : 'font-medium'
                                }
                            >
                                {label}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

/** Rows of a file that cannot be read, worded for the reader; the first ones, with how many more. */
export function ProblemList({ problems }: { problems: Problem[] }) {
    const { t } = useTranslation();
    const shown = problems.slice(0, 8);

    if (problems.length === 0) {
        return null;
    }

    return (
        <ul className="list-disc ps-5 text-sm text-destructive">
            {shown.map((problem, index) => (
                <li key={`${problem.line}:${index}`}>
                    {t(`conversion.problems.${problem.kind}`, {
                        line: problem.line,
                        detail: problem.detail,
                    })}
                </li>
            ))}
            {problems.length > shown.length && (
                <li>
                    {t('conversion.problems.more', {
                        count: problems.length - shown.length,
                    })}
                </li>
            )}
        </ul>
    );
}

/** What the server refused, as its own message followed by the distinct reasons it gave for fields. */
export function failureMessages(error: unknown, fallback: string): string[] {
    if (!isApiError(error) || error.status === 0) {
        return [fallback];
    }

    const reasons = [...new Set(Object.values(error.errors).flat())];

    return [
        error.message,
        ...reasons.filter((reason) => reason !== error.message),
    ].slice(0, 9);
}

/**
 * A file chosen from disk or pasted, the way the source system exports it. The text stays editable
 * so a row can be fixed without going back to the spreadsheet.
 */
export function CsvSourceField({
    label,
    hint,
    example,
    value,
    onChange,
}: {
    label: string;
    hint: string;
    example: string;
    value: string;
    onChange: (text: string) => void;
}) {
    const { t } = useTranslation();
    const id = useId();
    const fileInput = useRef<HTMLInputElement>(null);

    return (
        <Field>
            <FieldLabel htmlFor={`${id}-text`}>{label}</FieldLabel>
            <FieldDescription>{hint}</FieldDescription>
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => fileInput.current?.click()}
                >
                    {t('conversion.chooseFile')}
                </Button>
                <input
                    ref={fileInput}
                    id={`${id}-file`}
                    type="file"
                    accept=".csv,.txt,text/csv,text/plain"
                    className="sr-only"
                    aria-label={t('conversion.chooseFile')}
                    onChange={async (event) => {
                        const file = event.target.files?.[0];

                        if (file) {
                            onChange(await file.text());
                        }

                        // The same file can be chosen again after a change.
                        event.target.value = '';
                    }}
                />
            </div>
            <Textarea
                id={`${id}-text`}
                rows={8}
                className="font-mono text-xs"
                placeholder={example}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </Field>
    );
}

/** One row per source code, each with the account of the company it stands for. */
export function AccountMappingTable({
    codes,
    accounts,
    mapping,
    onChange,
}: {
    codes: string[];
    accounts: Account[];
    /** Source code => account ID. */
    mapping: Record<string, string>;
    onChange: (mapping: Record<string, string>) => void;
}) {
    const { t } = useTranslation();
    // Only accounts that take postings: active leaves.
    const items = accounts
        .filter((account) => account.status === 'active' && account.is_leaf)
        .map((account) => ({
            value: account.id,
            label: `${account.code} · ${account.name}`,
        }));

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-start text-muted-foreground">
                        <th className="px-3 py-2 text-start font-medium">
                            {t('conversion.sourceCode')}
                        </th>
                        <th className="px-3 py-2 text-start font-medium">
                            {t('conversion.account')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {codes.map((code) => (
                        <tr key={code} className="border-b last:border-0">
                            <td className="px-3 py-2 align-middle font-mono">
                                {code}
                            </td>
                            <td className="min-w-64 px-3 py-2">
                                <SearchableSelect
                                    aria-label={t('conversion.accountFor', {
                                        code,
                                    })}
                                    items={items}
                                    value={mapping[code] ?? ''}
                                    onValueChange={(accountId) =>
                                        onChange({
                                            ...mapping,
                                            [code]: accountId,
                                        })
                                    }
                                    placeholder={t('conversion.chooseAccount')}
                                    emptyText={t('common.noMatch')}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Pre-fills the mapping: a source code that equals the code of an account of the company is that account. */
export function suggestMapping(
    codes: string[],
    accounts: Account[],
    current: Record<string, string>,
): Record<string, string> {
    const byCode = new Map(
        accounts
            .filter((a) => a.status === 'active' && a.is_leaf)
            .map((a) => [a.code, a.id]),
    );
    const next = { ...current };

    for (const code of codes) {
        const match = byCode.get(code);

        if (next[code] === undefined && match !== undefined) {
            next[code] = match;
        }
    }

    return next;
}
