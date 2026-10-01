import { useTranslation } from 'react-i18next';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';

export type Option = { value: string; label: string };

type Props = {
    items: Option[];
    /** The chosen option's value, or empty for none. */
    value: string;
    onValueChange: (value: string) => void;
    id?: string;
    'aria-label'?: string;
    'aria-invalid'?: boolean;
    placeholder?: string;
    /** Shown when what was typed matches nothing. */
    emptyText: string;
    /** Lets the choice be cleared (a field that may stay empty). */
    clearable?: boolean;
    disabled?: boolean;
    className?: string;
};

/**
 * A select you can type into: the list narrows to the options whose text contains what was typed,
 * so a long list (a chart of accounts) is reached by code or name instead of by scrolling.
 */
export function SearchableSelect({
    items,
    value,
    onValueChange,
    id,
    placeholder,
    emptyText,
    clearable,
    disabled,
    className,
    ...aria
}: Props) {
    const { t } = useTranslation();
    const selected = items.find((item) => item.value === value) ?? null;

    return (
        <Combobox
            items={items}
            value={selected}
            onValueChange={(item) => onValueChange(item?.value ?? '')}
            itemToStringValue={(item) => item.label}
            isItemEqualToValue={(a, b) => a.value === b.value}
            disabled={disabled}
        >
            <ComboboxInput
                id={id}
                placeholder={placeholder}
                showClear={clearable}
                clearLabel={t('common.clear')}
                disabled={disabled}
                className={className ?? 'w-full'}
                {...aria}
            />
            <ComboboxContent>
                <ComboboxEmpty>{emptyText}</ComboboxEmpty>
                <ComboboxList>
                    {(item: Option) => (
                        <ComboboxItem key={item.value} value={item}>
                            {item.label}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
