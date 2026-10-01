import {
    useLayoutEffect,
    useRef,
    useState,
    type ChangeEvent,
    type ComponentProps,
} from 'react';
import { useTranslation } from 'react-i18next';
import { Input } from '@/components/ui/input';
import { formatCanonical, separatorsFor, typeAmount } from '@/lib/amount';
import { cn } from '@/lib/utils';

type Props = Omit<
    ComponentProps<typeof Input>,
    'value' | 'onChange' | 'type' | 'inputMode' | 'defaultValue'
> & {
    /** Canonical decimal string ("1500000.50", API-TR-05), or empty. */
    value: string;
    /** Receives the canonical string, never the text with separators. */
    onChange: (value: string) => void;
};

/**
 * A money field that groups thousands as the digits are typed ("1.500.000,50" in Indonesian) while
 * its value stays the plain decimal string the API takes. The caret keeps its place when a
 * separator is inserted, and a value set from outside (a loaded draft, a reset form) is shown
 * grouped too.
 */
export function MoneyInput({ value, onChange, className, ...props }: Props) {
    const { i18n } = useTranslation();
    const separators = separatorsFor(i18n.language);
    const inputRef = useRef<HTMLInputElement>(null);
    const caret = useRef<number | null>(null);
    const [text, setText] = useState(() => formatCanonical(value, separators));

    // Follow the value when it changes from outside, but never rewrite what the user is typing:
    // "12," and "12" are the same value and the trailing separator must stay.
    useLayoutEffect(() => {
        if (typeAmount(text, 0, separators).canonical !== value) {
            setText(formatCanonical(value, separators));
        }
    }, [value, text, separators.group, separators.decimal]);

    useLayoutEffect(() => {
        if (caret.current !== null && inputRef.current) {
            inputRef.current.setSelectionRange(caret.current, caret.current);
            caret.current = null;
        }
    }, [text]);

    const type = (event: ChangeEvent<HTMLInputElement>) => {
        const typed = typeAmount(
            event.target.value,
            event.target.selectionStart ?? event.target.value.length,
            separators,
        );

        caret.current = typed.caret;
        setText(typed.text);
        onChange(typed.canonical);
    };

    return (
        <Input
            {...props}
            ref={inputRef}
            type="text"
            inputMode="decimal"
            autoComplete="off"
            className={cn('text-right tabular-nums', className)}
            value={text}
            onChange={type}
        />
    );
}
