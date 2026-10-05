import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const ANY = '__any';

/** A filter that picks one of `options`, or none (the first entry, `anyLabel`). */
export function FilterSelect<T extends string>({
    label,
    anyLabel,
    options,
    value,
    onChange,
    className = 'w-44',
}: {
    label: string;
    anyLabel: string;
    options: { value: T; label: string }[];
    value: T | undefined;
    onChange: (value: T | undefined) => void;
    className?: string;
}) {
    const items = [{ value: ANY, label: anyLabel }, ...options];

    return (
        <Select
            value={value ?? ANY}
            items={items}
            onValueChange={(next) =>
                onChange(options.find((option) => option.value === next)?.value)
            }
        >
            <SelectTrigger className={className} aria-label={label}>
                <SelectValue />
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
