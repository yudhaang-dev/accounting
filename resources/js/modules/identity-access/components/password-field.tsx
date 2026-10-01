import { EyeIcon, EyeOffIcon } from 'lucide-react';
import { useState, type ComponentProps, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';

type Props = Omit<ComponentProps<typeof InputGroupInput>, 'type'> & {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    /** Rendered next to the label, for example the "Forgot password?" link. */
    labelAction?: ReactNode;
};

/**
 * Password input with a show/hide toggle and a Caps Lock warning. The toggle keeps focus (and the
 * caret) in the input, so revealing the password never interrupts typing.
 */
export function PasswordField({
    id,
    label,
    error,
    hint,
    labelAction,
    onKeyDown,
    onKeyUp,
    ...inputProps
}: Props) {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);
    const [capsLock, setCapsLock] = useState(false);

    return (
        <Field data-invalid={error ? true : undefined}>
            <div className="flex items-center justify-between gap-2">
                <FieldLabel htmlFor={id}>{label}</FieldLabel>
                {labelAction}
            </div>

            <InputGroup>
                <InputGroupInput
                    id={id}
                    type={visible ? 'text' : 'password'}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={
                        [error && `${id}-error`, hint && `${id}-hint`]
                            .filter(Boolean)
                            .join(' ') || undefined
                    }
                    onKeyDown={(event) => {
                        setCapsLock(event.getModifierState('CapsLock'));
                        onKeyDown?.(event);
                    }}
                    onKeyUp={(event) => {
                        setCapsLock(event.getModifierState('CapsLock'));
                        onKeyUp?.(event);
                    }}
                    {...inputProps}
                />
                <InputGroupAddon align="inline-end">
                    <InputGroupButton
                        size="icon-xs"
                        aria-label={
                            visible
                                ? t('common.hidePassword')
                                : t('common.showPassword')
                        }
                        aria-pressed={visible}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => setVisible((value) => !value)}
                    >
                        {visible ? <EyeOffIcon /> : <EyeIcon />}
                    </InputGroupButton>
                </InputGroupAddon>
            </InputGroup>

            {capsLock && (
                <FieldDescription
                    role="status"
                    className="text-amber-600 motion-safe:animate-in motion-safe:fade-in dark:text-amber-400"
                >
                    {t('common.capsLock')}
                </FieldDescription>
            )}
            {hint && !error && (
                <FieldDescription id={`${id}-hint`}>{hint}</FieldDescription>
            )}
            {error && <FieldError id={`${id}-error`}>{error}</FieldError>}
        </Field>
    );
}
