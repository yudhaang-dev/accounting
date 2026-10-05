import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Label } from '@/components/ui/label';
import { actionErrorMessage } from '@/lib/errors';
import type { Period } from '@/modules/general-ledger/periods';
import { copyLines, type PlanVersion } from '@/modules/planning/plans';

type Source = 'version' | 'actuals';

function CopyForm({
    companyId,
    version,
    siblings,
    periods,
    onDone,
}: {
    companyId: string;
    version: PlanVersion;
    /** Other versions of the same fiscal year that can be copied from. */
    siblings: { id: string; label: string }[];
    periods: Period[];
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [source, setSource] = useState<Source>(
        siblings.length > 0 ? 'version' : 'actuals',
    );
    const [fromVersion, setFromVersion] = useState('');
    const [actualsPeriod, setActualsPeriod] = useState('');
    const [toPeriod, setToPeriod] = useState('');
    const [formError, setFormError] = useState<string | null>(null);

    const periodItems = periods.map((period) => ({
        value: period.id,
        label: period.name,
    }));

    const copy = useMutation({
        mutationFn: () =>
            copyLines(
                companyId,
                version.id,
                source === 'version'
                    ? { from_version_id: fromVersion }
                    : {
                          actuals_period_id: actualsPeriod,
                          to_period_id: toPeriod,
                      },
            ),
        onSuccess: async ({ created, updated, skipped }) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'plans'],
            });
            toast.success(
                t('plans.copy.done', {
                    created,
                    updated,
                    skipped: skipped ?? 0,
                }),
            );
            onDone();
        },
        onError: (error) => setFormError(actionErrorMessage(error, t)),
    });

    const ready =
        source === 'version'
            ? fromVersion !== ''
            : actualsPeriod !== '' && toPeriod !== '';

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);

                if (!ready) {
                    setFormError(t('plans.copy.incomplete'));

                    return;
                }

                copy.mutate();
            }}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <RadioGroup
                    value={source}
                    onValueChange={(value) => setSource(value as Source)}
                    aria-label={t('plans.copy.source')}
                >
                    <Label className="flex items-center gap-2 font-normal">
                        <RadioGroupItem
                            value="version"
                            disabled={siblings.length === 0}
                        />
                        {t('plans.copy.fromVersion')}
                    </Label>
                    <Label className="flex items-center gap-2 font-normal">
                        <RadioGroupItem value="actuals" />
                        {t('plans.copy.fromActuals')}
                    </Label>
                </RadioGroup>

                {source === 'version' ? (
                    <Field>
                        <FieldLabel htmlFor="copy-version">
                            {t('plans.copy.version')}
                        </FieldLabel>
                        <SearchableSelect
                            id="copy-version"
                            items={siblings.map((s) => ({
                                value: s.id,
                                label: s.label,
                            }))}
                            value={fromVersion}
                            onValueChange={setFromVersion}
                            emptyText={t('common.noMatch')}
                        />
                    </Field>
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="copy-actuals">
                                {t('plans.copy.actualsPeriod')}
                            </FieldLabel>
                            <SearchableSelect
                                id="copy-actuals"
                                items={periodItems}
                                value={actualsPeriod}
                                onValueChange={setActualsPeriod}
                                emptyText={t('common.noMatch')}
                            />
                        </Field>
                        <Field>
                            <FieldLabel htmlFor="copy-target">
                                {t('plans.copy.toPeriod')}
                            </FieldLabel>
                            <SearchableSelect
                                id="copy-target"
                                items={periodItems}
                                value={toPeriod}
                                onValueChange={setToPeriod}
                                emptyText={t('common.noMatch')}
                            />
                        </Field>
                    </>
                )}

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={copy.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('plans.copy.submit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Fills a draft from another version of the year or from what was posted in a period; cells that exist take the copied amounts (PL-FR-03). */
export function CopyLinesDialog({
    companyId,
    version,
    siblings,
    periods,
    open,
    onOpenChange,
}: {
    companyId: string;
    version: PlanVersion;
    siblings: { id: string; label: string }[];
    periods: Period[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('plans.copy.title')}
            description={t('plans.copy.description')}
        >
            <CopyForm
                companyId={companyId}
                version={version}
                siblings={siblings}
                periods={periods}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
