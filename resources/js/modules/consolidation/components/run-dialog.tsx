import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { todayIso } from '@/lib/date';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { createRun, type Run } from '@/modules/consolidation/groups';

function RunForm({
    groupId,
    onDone,
    onCreated,
}: {
    groupId: string;
    onDone: () => void;
    onCreated: (run: Run) => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const today = todayIso();
    const [from, setFrom] = useState(`${today.slice(0, 4)}-01-01`);
    const [to, setTo] = useState(today);
    const [problems, setProblems] = useState<string[]>([]);

    const run = useMutation({
        mutationFn: () => createRun(groupId, from, to),
        onSuccess: async (created) => {
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'runs'],
            });
            onCreated(created);
        },
        onError: (error) => {
            // A refused run names each company and the reason, so all of them are shown, not just the first.
            setProblems(
                isApiError(error) && error.status === 422
                    ? Object.values(error.errors).flat()
                    : [actionErrorMessage(error, t)],
            );
        },
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setProblems([]);
                run.mutate();
            }}
        >
            <FieldGroup>
                {problems.length > 0 && (
                    <FormAlert>
                        <ul className="list-disc ps-4">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    </FormAlert>
                )}

                <Field>
                    <FieldLabel id="run-from-label">
                        {t('consolidation.runs.from')}
                    </FieldLabel>
                    <DatePicker
                        labelledBy="run-from-label"
                        value={from}
                        onChange={setFrom}
                    />
                </Field>
                <Field>
                    <FieldLabel id="run-to-label">
                        {t('consolidation.runs.to')}
                    </FieldLabel>
                    <DatePicker
                        labelledBy="run-to-label"
                        value={to}
                        onChange={setTo}
                    />
                    <FieldDescription>
                        {t('consolidation.runs.toHint')}
                    </FieldDescription>
                </Field>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        pending={run.isPending}
                        pendingLabel={t('consolidation.runs.running')}
                        disabled={from === '' || to === ''}
                    >
                        {t('consolidation.runs.run')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Runs the management roll-up for a range (CO-FR-04); the date the books are read up to is the end of the range. */
export function RunDialog({
    groupId,
    open,
    onOpenChange,
    onCreated,
}: {
    groupId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (run: Run) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('consolidation.runs.runTitle')}
            description={t('consolidation.runs.runDescription')}
        >
            <RunForm
                groupId={groupId}
                onDone={() => onOpenChange(false)}
                onCreated={(run) => {
                    onOpenChange(false);
                    onCreated(run);
                }}
            />
        </FormDialog>
    );
}
