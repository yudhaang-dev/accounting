import { Undo2Icon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { ReverseJournalDialog } from '@/modules/transaction-entry/components/reverse-journal-dialog';

/**
 * The button that starts the reversal of a journal and the dialog it opens. The journal screen belongs to
 * another module, so the app hands this button to it instead of that screen importing this module.
 */
export function ReverseJournalButton({
    companyId,
    journalId,
    journalNumber,
    variant = 'outline',
}: {
    companyId: string;
    journalId: string;
    journalNumber: string;
    variant?: 'outline' | 'default';
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button variant={variant} onClick={() => setOpen(true)}>
                <Undo2Icon data-icon="inline-start" />
                {t('reversal.action')}
            </Button>
            <ReverseJournalDialog
                companyId={companyId}
                journalId={journalId}
                journalNumber={journalNumber}
                open={open}
                onOpenChange={setOpen}
            />
        </>
    );
}
