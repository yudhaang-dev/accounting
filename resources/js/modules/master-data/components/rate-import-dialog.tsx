import { useMutation, useQueryClient } from '@tanstack/react-query';
import { UploadIcon } from 'lucide-react';
import { useRef, useState } from 'react';
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
    SHOWN_PROBLEMS as SHOWN,
    type Problem,
} from '@/lib/csv-import';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { importRates } from '@/modules/master-data/exchange-rates';

function RateImportForm({
    companyId,
    onDone,
}: {
    companyId: string;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [problems, setProblems] = useState<Problem[]>([]);

    const upload = useMutation({
        mutationFn: async (chosen: File) =>
            importRates(companyId, await readText(chosen)),
        onSuccess: async ({ created, unchanged }) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'exchange-rates'],
            });
            toast.success(t('rates.import.done', { created, unchanged }));
            onDone();
        },
        onError: (error) => {
            if (isApiError(error) && error.status === 422) {
                const found = problemsOf(error.errors);

                if (found.length > 0) {
                    setProblems(found);
                    setFormError(t('rates.import.rejected'));

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
                    setFormError(t('rates.import.chooseFile'));

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
                                aria-label={t('rates.import.problems')}
                            >
                                {problems.slice(0, SHOWN).map((problem) => (
                                    <li
                                        key={`${problem.line}-${problem.field}-${problem.message}`}
                                    >
                                        {problem.line &&
                                            t('rates.import.line', {
                                                line: problem.line,
                                            })}{' '}
                                        {problem.field && `${problem.field}: `}
                                        {problem.message}
                                    </li>
                                ))}
                                {problems.length > SHOWN && (
                                    <li>
                                        {t('rates.import.more', {
                                            count: problems.length - SHOWN,
                                        })}
                                    </li>
                                )}
                            </ul>
                        )}
                    </FormAlert>
                )}

                <Field>
                    <FieldLabel htmlFor="rate-import-file">
                        {t('rates.import.file')}
                    </FieldLabel>
                    <Input
                        id="rate-import-file"
                        ref={input}
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        onChange={(event) =>
                            setFile(event.target.files?.[0] ?? null)
                        }
                    />
                    <FieldDescription>
                        {t('rates.import.format')}
                    </FieldDescription>
                    <pre className="overflow-x-auto rounded-md bg-muted px-3 py-2 font-mono text-xs">
                        {
                            'rate_type,currency,effective_date,rate\ncentral_bank,USD,2026-03-10,16250.50'
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
                        pendingLabel={t('rates.import.importing')}
                    >
                        <UploadIcon data-icon="inline-start" />
                        {t('rates.import.submit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Imports rates from a CSV file (MD-FR-07). All or nothing: a bad row rejects the file and is listed. */
export function RateImportDialog({
    companyId,
    open,
    onOpenChange,
}: {
    companyId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('rates.import.title')}
            description={t('rates.import.description')}
        >
            <RateImportForm
                companyId={companyId}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
