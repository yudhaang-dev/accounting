import { CalendarIcon } from 'lucide-react';
import { useState } from 'react';
import { enUS, id as indonesian } from 'react-day-picker/locale';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatIsoDate, parseIsoDate, todayIso, toIsoDate } from '@/lib/date';
import { cn } from '@/lib/utils';

type Props = {
    /** `YYYY-MM-DD`, or empty for no date. */
    value: string;
    onChange: (value: string) => void;
    id?: string;
    /** Id of the visible label: the button is named by it and by the date it shows. */
    labelledBy?: string;
    'aria-invalid'?: boolean;
    placeholder?: string;
    /** Lets the date be removed (a field that may stay empty). */
    clearable?: boolean;
    disabled?: boolean;
    className?: string;
};

/**
 * A date field that opens a calendar in a popover instead of asking for typed digits. The value is
 * the same `YYYY-MM-DD` string the API takes; the button shows it written out for the reader's
 * language. Years can be jumped to from the caption, because accounting dates are rarely this month.
 */
export function DatePicker({
    value,
    onChange,
    id,
    labelledBy,
    placeholder,
    clearable,
    disabled,
    className,
    ...aria
}: Props) {
    const { t, i18n } = useTranslation();
    const [open, setOpen] = useState(false);
    const selected = parseIsoDate(value);
    const choose = (next: string) => {
        onChange(next);
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        disabled={disabled}
                        aria-labelledby={
                            labelledBy && id ? `${labelledBy} ${id}` : undefined
                        }
                        className={cn(
                            'w-full justify-start font-normal',
                            !selected && 'text-muted-foreground',
                            className,
                        )}
                        {...aria}
                    />
                }
            >
                <CalendarIcon data-icon="inline-start" aria-hidden />
                {selected
                    ? formatIsoDate(value, i18n.language)
                    : (placeholder ?? t('common.pickDate'))}
            </PopoverTrigger>
            <PopoverContent align="start" className="w-auto p-0">
                <Calendar
                    mode="single"
                    selected={selected}
                    onSelect={(date) => date && choose(toIsoDate(date))}
                    defaultMonth={selected ?? new Date()}
                    captionLayout="dropdown"
                    startMonth={new Date(2000, 0)}
                    endMonth={new Date(2100, 11)}
                    locale={i18n.language === 'en' ? enUS : indonesian}
                />
                <div className="flex justify-between gap-2 border-t p-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => choose(todayIso())}
                    >
                        {t('common.today')}
                    </Button>
                    {clearable && selected && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => choose('')}
                        >
                            {t('common.clear')}
                        </Button>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
