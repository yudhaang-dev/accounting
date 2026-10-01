import { CircleAlertIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';

type Props = {
    children: ReactNode;
    /** Change this value to replay the shake, e.g. a counter of failed attempts. */
    attempt?: number;
};

/**
 * Form-level error. It is announced to screen readers, and a short horizontal shake draws the eye
 * on each new failure (skipped when the system prefers reduced motion).
 */
export function FormAlert({ children, attempt = 0 }: Props) {
    return (
        <div key={attempt} className="motion-safe:animate-shake">
            <Alert variant="destructive" role="alert">
                <CircleAlertIcon aria-hidden />
                <AlertDescription>{children}</AlertDescription>
            </Alert>
        </div>
    );
}
