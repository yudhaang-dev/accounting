import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /** Read by assistive technology; the title already says what the dialog is for. */
    description: string;
    /** Wider for forms with side-by-side fields. */
    size?: 'md' | 'lg';
    /** The form. It is mounted only while the dialog is open, so it starts fresh each time. */
    children: ReactNode;
};

/** A dialog around one form: title, hidden description, and a form rebuilt on every opening. */
export function FormDialog({
    open,
    onOpenChange,
    title,
    description,
    size = 'md',
    children,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={size === 'lg' ? 'sm:max-w-xl' : 'sm:max-w-lg'}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {description}
                    </DialogDescription>
                </DialogHeader>
                {open && children}
            </DialogContent>
        </Dialog>
    );
}
