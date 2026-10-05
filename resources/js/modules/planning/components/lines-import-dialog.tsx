import { useMutation, useQueryClient } from '@tanstack/react-query';
import { UploadIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
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
import { Input } from '@/components/ui/input';
import {
    problemsOf,
    readText,
    SHOWN_PROBLEMS,
    type Problem,
} from '@/lib/csv-import';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { importLines } from '@/modules/planning/plans';

function LinesImportForm({
    companyId,
    versionId,
    onDone,
}: {
    companyId: string;
    versionId: string;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [file, setFile] = useState<File | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [problems, setProblems] = useState<Problem[]>([]);

    const upload = useMutation({
        mutationFn: async (chosen: File) =>
            importLines(companyId, versionId, await readText(chosen)),
        onSuccess: async ({ created, updated }) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'plans'],
            });
            toast.success(t('plans.import.done', { created, updated }));
            onDone();
        },
        onError: (error) => {
            if (isApiError(error) && error.status === 422) {
                const found = problemsOf(error.errors);

                if (found.length > 0) {
                    setProblems(found);
                    setFormError(t('plans.import.rejected'));

                    return;
                }
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                setProblems([]);

                if (file === null) {
                    setFormError(t('plans.import.chooseFile'));

                    return;
                }

                upload.mutate(file);
            }}
        >
            <FieldGroup>
                {formError && (
                    <FormAlert>
                        {formError}
                        {problems.length > 0 && (
                            <ul
                                className="mt-2 flex max-h-48 flex-col gap-0.5 overflow-y-auto text-sm"
                                aria-label={t('plans.import.problems')}
                            >
                                {problems.slice(0, SHOWN_PROBLEMS).map((p) => (
                                    <li
                                        key={`${p.line}-${p.field}-${p.message}`}
                                    >
                                        {p.line &&
                                            t('plans.import.line', {
                                                line: p.line,
                                            })}{' '}
                                        {p.field && `${p.field}: `}
                                        {p.message}
                                    </li>
                                ))}
                                {problems.length > SHOWN_PROBLEMS && (
                                    <li>
                                        {t('plans.import.more', {
                                            count:
                                                problems.length -
                                                SHOWN_PROBLEMS,
                                        })}
                                    </li>
                                )}
                            </ul>
                        )}
                    </FormAlert>
                )}

                <Field>
                    <FieldLabel htmlFor="plan-import-file">
                        {t('plans.import.file')}
                    </FieldLabel>
                    <Input
                        id="plan-import-file"
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        onChange={(event) =>
                            setFile(event.target.files?.[0] ?? null)
                        }
                    />
                    <FieldDescription>
                        {t('plans.import.format')}
                    </FieldDescription>
                    <pre className="overflow-x-auto rounded-md bg-muted px-3 py-2 font-mono text-xs">
                        {
                            'period,account_code,amount,note,dimension.branch\n2026-01-15,6100,1500000.00,Sewa kantor,JKT'
                        }
                    </pre>
                </Field>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={upload.isPending}
                        pendingLabel={t('plans.import.importing')}
                    >
                        <UploadIcon data-icon="inline-start" />
                        {t('plans.import.submit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Imports lines of a draft version from a CSV file (PL-FR-03). All or nothing: a bad row rejects the file and is listed (PL-AC-02). */
export function LinesImportDialog({
    companyId,
    versionId,
    open,
    onOpenChange,
}: {
    companyId: string;
    versionId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('plans.import.title')}
            description={t('plans.import.description')}
        >
            <LinesImportForm
                companyId={companyId}
                versionId={versionId}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
