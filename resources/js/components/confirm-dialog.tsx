import { useTranslation } from 'react-i18next';
import { Spinner } from '@/components/ui/spinner';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /** Says what will happen and whether it can be undone: the point of asking. */
    description: string;
    confirmLabel: string;
    /** True while the action runs: the dialog stays open and cannot be confirmed twice. */
    pending?: boolean;
    /** For an action that is hard to take back. */
    destructive?: boolean;
    onConfirm: () => void;
};

/**
 * Asks before an action that is hard to undo (posting a journal, closing a period, activating a
 * company). It stays open while the request runs, so a failure can be shown next to the question
 * and a double click cannot send it twice.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    pending = false,
    destructive = false,
    onConfirm,
}: Props) {
    const { t } = useTranslation();

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!pending) {
                    onOpenChange(next);
                }
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={pending}>
                        {t('common.cancel')}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={pending}
                        aria-busy={pending}
                        onClick={onConfirm}
                    >
                        {pending && (
                            <Spinner data-icon="inline-start" aria-hidden />
                        )}
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
