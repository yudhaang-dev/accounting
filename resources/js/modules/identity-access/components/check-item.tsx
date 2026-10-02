import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';

/** One checkbox with its label (and an optional line under it), the unit of every list on these screens. */
export function CheckItem({
    id,
    label,
    description,
    checked,
    disabled,
    onChange,
}: {
    id: string;
    label: ReactNode;
    description?: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <Field orientation="horizontal">
            <Checkbox
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={(next) => onChange(next === true)}
            />
            <div className="flex flex-col gap-0.5">
                <FieldLabel htmlFor={id} className="font-normal">
                    {label}
                </FieldLabel>
                {description && (
                    <FieldDescription>{description}</FieldDescription>
                )}
            </div>
        </Field>
    );
}

/** Adds or removes one value of a list, keeping the order it already had. */
export function toggled<T>(list: T[], value: T, on: boolean): T[] {
    return on
        ? list.includes(value)
            ? list
            : [...list, value]
        : list.filter((item) => item !== value);
}
