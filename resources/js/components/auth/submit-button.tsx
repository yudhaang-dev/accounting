import type { ComponentProps, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = Omit<ComponentProps<typeof Button>, 'type' | 'children'> & {
    pending: boolean;
    pendingLabel: string;
    children: ReactNode;
};

/**
 * Submit button with built-in feedback: it disables itself while the request runs (no double
 * submits), shows a spinner, and swaps its label so the click visibly "took".
 */
export function SubmitButton({
    pending,
    pendingLabel,
    children,
    disabled,
    className,
    ...props
}: Props) {
    return (
        <Button
            type="submit"
            className={className ?? 'w-full'}
            disabled={pending || disabled}
            aria-busy={pending}
            {...props}
        >
            {pending && <Spinner data-icon="inline-start" aria-hidden />}
            {pending ? pendingLabel : children}
        </Button>
    );
}
