import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    createFiscalCalendar,
    createFiscalYears,
    fiscalCalendarQuery,
    fiscalYearsQuery,
} from '@/modules/general-ledger/calendar';

const firstMessage = (error: unknown): string | undefined =>
    isApiError(error)
        ? (Object.values(error.errors)[0]?.[0] ?? error.message)
        : undefined;

function useMonthNames(): string[] {
    const { i18n } = useTranslation();

    return useMemo(() => {
        const format = new Intl.DateTimeFormat(i18n.language, {
            month: 'long',
        });

        return Array.from({ length: 12 }, (_, index) =>
            format.format(new Date(2000, index, 1)),
        );
    }, [i18n.language]);
}

function CalendarForm({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const months = useMonthNames();
    const [startMonth, setStartMonth] = useState(1);
    const [formError, setFormError] = useState<string | null>(null);

    const save = useMutation({
        mutationFn: () =>
            createFiscalCalendar(companyId, { start_month: startMonth }),
        onSuccess: () =>
            queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            }),
        onError: (error) =>
            setFormError(firstMessage(error) ?? genericErrorMessage(error, t)),
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                save.mutate();
            }}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field>
                    <FieldLabel>{t('setup.calendar.pattern')}</FieldLabel>
                    <p className="text-sm">
                        {t('setup.calendar.patternMonthly')}
                    </p>
                </Field>

                <Field>
                    <FieldLabel htmlFor="calendar-start-month">
                        {t('setup.calendar.startMonth')}
                    </FieldLabel>
                    <Select
                        value={String(startMonth)}
                        items={months.map((label, index) => ({
                            value: String(index + 1),
                            label,
                        }))}
                        onValueChange={(value) => setStartMonth(Number(value))}
                    >
                        <SelectTrigger
                            id="calendar-start-month"
                            className="w-48"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {months.map((label, index) => (
                                    <SelectItem
                                        key={label}
                                        value={String(index + 1)}
                                    >
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <FieldDescription>
                        {t('setup.calendar.startMonthHint')}
                    </FieldDescription>
                </Field>

                <SubmitButton
                    className="w-auto self-start"
                    pending={save.isPending}
                    pendingLabel={t('setup.calendar.saving')}
                >
                    {t('setup.calendar.saveCalendar')}
                </SubmitButton>
            </FieldGroup>
        </form>
    );
}

function YearsForm({
    companyId,
    startMonth,
}: {
    companyId: string;
    startMonth: number;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [year, setYear] = useState(String(new Date().getFullYear()));
    const [count, setCount] = useState('1');
    const [formError, setFormError] = useState<string | null>(null);
    const [yearError, setYearError] = useState<string | null>(null);

    const create = useMutation({
        mutationFn: () =>
            createFiscalYears(companyId, {
                start_date: `${year}-${String(startMonth).padStart(2, '0')}-01`,
                count: Number(count),
            }),
        onSuccess: () =>
            queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            }),
        onError: (error) => {
            if (isApiError(error) && error.errors.start_date) {
                setYearError(error.errors.start_date[0] ?? error.message);

                return;
            }

            setFormError(firstMessage(error) ?? genericErrorMessage(error, t));
        },
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                setYearError(null);

                if (!/^\d{4}$/.test(year)) {
                    setYearError(t('common.required'));

                    return;
                }

                create.mutate();
            }}
        >
            <FieldGroup>
                <p className="text-sm text-muted-foreground">
                    {t('setup.calendar.yearsIntro')}
                </p>
                {formError && <FormAlert>{formError}</FormAlert>}

                <div className="flex flex-wrap gap-4">
                    <Field
                        className="w-40"
                        data-invalid={yearError ? true : undefined}
                    >
                        <FieldLabel htmlFor="calendar-year">
                            {t('setup.calendar.startYear')}
                        </FieldLabel>
                        <Input
                            id="calendar-year"
                            inputMode="numeric"
                            maxLength={4}
                            value={year}
                            aria-invalid={yearError ? true : undefined}
                            onChange={(event) => setYear(event.target.value)}
                        />
                        {yearError && <FieldError>{yearError}</FieldError>}
                    </Field>
                    <Field className="w-40">
                        <FieldLabel htmlFor="calendar-count">
                            {t('setup.calendar.count')}
                        </FieldLabel>
                        <Input
                            id="calendar-count"
                            type="number"
                            min={1}
                            max={10}
                            value={count}
                            onChange={(event) => setCount(event.target.value)}
                        />
                    </Field>
                </div>

                <SubmitButton
                    className="w-auto self-start"
                    pending={create.isPending}
                    pendingLabel={t('setup.calendar.creating')}
                >
                    {t('setup.calendar.createYears')}
                </SubmitButton>
            </FieldGroup>
        </form>
    );
}

/**
 * Fiscal calendar step (GL-FR-03): the calendar first, then the fiscal years whose monthly periods
 * follow without gaps. Which part is shown comes from what the server already has, so a reload (or
 * a failure between the two calls) resumes where it stopped.
 */
export function CalendarStep({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const months = useMonthNames();
    const calendar = useQuery(fiscalCalendarQuery(companyId));
    const years = useQuery(fiscalYearsQuery(companyId));

    if (calendar.isError || years.isError) {
        return (
            <FormAlert>
                {genericErrorMessage(calendar.error ?? years.error, t)}
            </FormAlert>
        );
    }

    if (calendar.data === undefined || years.data === undefined) {
        return <Skeleton className="h-24 w-full" />;
    }

    if (calendar.data === null) {
        return <CalendarForm companyId={companyId} />;
    }

    if (years.data.length === 0) {
        return (
            <YearsForm
                companyId={companyId}
                startMonth={calendar.data.start_month}
            />
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <p className="font-medium">{t('setup.calendar.doneTitle')}</p>
                <p className="text-sm text-muted-foreground">
                    {t('setup.calendar.doneDescription', {
                        month: months[calendar.data.start_month - 1],
                        count: years.data.length,
                    })}
                </p>
            </div>
            <ul className="flex flex-col gap-1 text-sm">
                {years.data.map((year) => (
                    <li key={year.id} className="flex gap-2">
                        <span className="font-medium">{year.name}</span>
                        <span className="text-muted-foreground">
                            {year.start_date} – {year.end_date} ·{' '}
                            {t('setup.calendar.periods', {
                                count: Number(year.period_count),
                            })}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
