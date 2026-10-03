import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type OptionSelectItem = { value: string; label: string };

/** A plain select over a short list of options: the shape every picker of the designer takes. */
export function OptionSelect({
    id,
    value,
    items,
    onValueChange,
    placeholder,
    disabled,
    className,
    ...aria
}: {
    id?: string;
    value: string;
    items: OptionSelectItem[];
    onValueChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
    'aria-invalid'?: boolean;
}) {
    return (
        <Select
            value={value === '' ? null : value}
            items={items}
            disabled={disabled}
            onValueChange={(next) => onValueChange(next ?? '')}
        >
            <SelectTrigger id={id} className={className ?? 'w-full'} {...aria}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    {items.map((item) => (
                        <SelectItem key={item.value} value={item.value}>
                            {item.label}
                        </SelectItem>
                    ))}
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}
