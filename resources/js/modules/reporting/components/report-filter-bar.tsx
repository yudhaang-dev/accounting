import { useQuery } from '@tanstack/react-query';
import { ChevronDownIcon } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    JOURNAL_SOURCES,
    filterOptionsQuery,
    hasNarrowing,
    validateFilter,
    type DateMode,
    type ReportFilter,
} from '@/modules/reporting/report-filter';

type Props = {
    companyId: string;
    /** The filter the report currently shows. */
    value: ReportFilter;
    /** Called with the draft once it validates. */
    onApply: (filter: ReportFilter) => void;
    /** `range` shows from/to, `as_of` a single date. */
    dateMode?: DateMode;
    /** The plan version picker, for reports that compare with a plan (RP-FR-11). */
    withPlanVersion?: boolean;
    /** The workpaper picker: Jenis Transaksi keys counted as adjustments (RP-FR-05). */
    withWorkpaper?: boolean;
    /** Extra controls placed after the apply button. */
    children?: ReactNode;
};

/**
 * The filters every report shares (RP-FR-04): dates, and under "more filters" a dimension value per type,
 * Jenis Transaksi keys, the posting source, and a plan version. Nothing is requested until the viewer
 * presses Show, so editing the draft never reloads the report. A parent dimension value covers its
 * children on the server, so the picker only lists values.
 */
export function ReportFilterBar({
    companyId,
    value,
    onApply,
    dateMode = 'range',
    withPlanVersion = false,
    withWorkpaper = false,
    children,
}: Props) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<ReportFilter>(value);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [open, setOpen] = useState(
        hasNarrowing(value) || value.adjustment_keys.length > 0,
    );
    const options = useQuery(filterOptionsQuery(companyId, open));
    const narrowing = hasNarrowing(draft);

    const show = (event: FormEvent) => {
        event.preventDefault();

        const found = validateFilter(draft, dateMode);

        setErrors(
            Object.fromEntries(
                Object.entries(found).map(([field, key]) => [field, t(key)]),
            ),
        );

        if (Object.keys(found).length === 0) {
            onApply(draft);
        }
    };

    const toggle = (
        field: 'transaction_type_keys' | 'adjustment_keys',
        key: string,
        checked: boolean,
    ) =>
        setDraft({
            ...draft,
            [field]: checked
                ? [...draft[field], key]
                : draft[field].filter((k) => k !== key),
        });

    const dateField = (field: 'from' | 'to' | 'as_of') => (
        <Field
            key={field}
            className="w-56"
            data-invalid={errors[field] ? true : undefined}
        >
            <FieldLabel id={`rf-${field}-label`} htmlFor={`rf-${field}`}>
                {t(`reports.filter.${field}`)}
            </FieldLabel>
            <DatePicker
                id={`rf-${field}`}
                labelledBy={`rf-${field}-label`}
                value={draft[field]}
                aria-invalid={errors[field] ? true : undefined}
                onChange={(date) => setDraft({ ...draft, [field]: date })}
            />
            {errors[field] && <FieldError>{errors[field]}</FieldError>}
        </Field>
    );

    return (
        <form noValidate onSubmit={show} className="flex flex-col gap-3">
            <div className="flex flex-wrap items-start gap-3">
                {dateMode === 'range' ? (
                    <>
                        {dateField('from')}
                        {dateField('to')}
                    </>
                ) : (
                    dateField('as_of')
                )}
                <div className="flex items-center gap-2 self-end">
                    <Button type="submit">{t('reports.filter.show')}</Button>
                    {children}
                </div>
            </div>

            <Collapsible open={open} onOpenChange={setOpen}>
                <CollapsibleTrigger
                    className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                >
                    <ChevronDownIcon
                        data-icon="inline-start"
                        className={open ? 'rotate-180' : undefined}
                    />
                    {t('reports.filter.more')}
                    {narrowing && ` · ${t('reports.filter.active')}`}
                </CollapsibleTrigger>
                <CollapsibleContent className="flex flex-col gap-4 pt-3">
                    {options.isError ? (
                        <FormAlert>
                            {t('reports.filter.optionsFailed')}
                        </FormAlert>
                    ) : (
                        <>
                            <div className="flex flex-wrap gap-3">
                                {options.data?.dimensionTypes.map((type) => (
                                    <Field key={type.key} className="w-56">
                                        <FieldLabel
                                            htmlFor={`rf-dim-${type.key}`}
                                        >
                                            {type.label}
                                        </FieldLabel>
                                        <SearchableSelect
                                            id={`rf-dim-${type.key}`}
                                            items={type.values.map((v) => ({
                                                value: v.id,
                                                label: `${v.code} · ${v.name}`,
                                            }))}
                                            value={
                                                draft.dimensions[type.key] ?? ''
                                            }
                                            onValueChange={(id) =>
                                                setDraft({
                                                    ...draft,
                                                    dimensions: {
                                                        ...draft.dimensions,
                                                        [type.key]: id,
                                                    },
                                                })
                                            }
                                            placeholder={t(
                                                'reports.filter.allValues',
                                            )}
                                            emptyText={t('common.noMatch')}
                                            clearable
                                        />
                                    </Field>
                                ))}
                                <Field className="w-56">
                                    <FieldLabel htmlFor="rf-source">
                                        {t('reports.filter.source')}
                                    </FieldLabel>
                                    <SearchableSelect
                                        id="rf-source"
                                        items={JOURNAL_SOURCES.map(
                                            (source) => ({
                                                value: source,
                                                label: t(
                                                    `journals.sources.${source}`,
                                                    { defaultValue: source },
                                                ),
                                            }),
                                        )}
                                        value={draft.source}
                                        onValueChange={(source) =>
                                            setDraft({
                                                ...draft,
                                                source: source as ReportFilter['source'],
                                            })
                                        }
                                        placeholder={t(
                                            'reports.filter.allSources',
                                        )}
                                        emptyText={t('common.noMatch')}
                                        clearable
                                    />
                                </Field>
                                {withPlanVersion && (
                                    <Field className="w-56">
                                        <FieldLabel htmlFor="rf-plan">
                                            {t('reports.filter.planVersion')}
                                        </FieldLabel>
                                        <SearchableSelect
                                            id="rf-plan"
                                            items={(
                                                options.data?.planVersions ?? []
                                            ).map((v) => ({
                                                value: v.id,
                                                label: v.name,
                                            }))}
                                            value={draft.plan_version_id}
                                            onValueChange={(id) =>
                                                setDraft({
                                                    ...draft,
                                                    plan_version_id: id,
                                                })
                                            }
                                            placeholder={t(
                                                'reports.filter.defaultPlan',
                                            )}
                                            emptyText={t('common.noMatch')}
                                            clearable
                                        />
                                    </Field>
                                )}
                            </div>

                            {options.data &&
                                options.data.transactionTypes.length > 0 && (
                                    <KeyChecklist
                                        legend={t(
                                            'reports.filter.transactionTypes',
                                        )}
                                        idPrefix="rf-tt"
                                        types={options.data.transactionTypes}
                                        selected={draft.transaction_type_keys}
                                        onToggle={(key, checked) =>
                                            toggle(
                                                'transaction_type_keys',
                                                key,
                                                checked,
                                            )
                                        }
                                    />
                                )}
                            {withWorkpaper &&
                                options.data &&
                                options.data.transactionTypes.length > 0 && (
                                    <KeyChecklist
                                        legend={t(
                                            'reports.filter.adjustmentKeys',
                                        )}
                                        hint={t(
                                            'reports.filter.adjustmentKeysHint',
                                        )}
                                        idPrefix="rf-adj"
                                        types={options.data.transactionTypes}
                                        selected={draft.adjustment_keys}
                                        onToggle={(key, checked) =>
                                            toggle(
                                                'adjustment_keys',
                                                key,
                                                checked,
                                            )
                                        }
                                    />
                                )}
                        </>
                    )}
                </CollapsibleContent>
            </Collapsible>
        </form>
    );
}

function KeyChecklist({
    legend,
    hint,
    idPrefix,
    types,
    selected,
    onToggle,
}: {
    legend: string;
    hint?: string;
    idPrefix: string;
    types: { key: string; label: string }[];
    selected: string[];
    onToggle: (key: string, checked: boolean) => void;
}) {
    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">{legend}</legend>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <div className="flex flex-wrap gap-x-5 gap-y-2">
                {types.map((type) => (
                    <Field
                        key={type.key}
                        orientation="horizontal"
                        className="w-auto"
                    >
                        <Checkbox
                            id={`${idPrefix}-${type.key}`}
                            checked={selected.includes(type.key)}
                            onCheckedChange={(checked) =>
                                onToggle(type.key, checked === true)
                            }
                        />
                        <FieldLabel
                            htmlFor={`${idPrefix}-${type.key}`}
                            className="font-normal"
                        >
                            {type.label}
                        </FieldLabel>
                    </Field>
                ))}
            </div>
        </fieldset>
    );
}
