import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { toast } from "sonner";
import { FormAlert } from "@/components/form-alert";
import { FormDialog } from "@/components/form-dialog";
import { SubmitButton } from "@/components/submit-button";
import { Button } from "@/components/ui/button";
import { DialogFooter } from "@/components/ui/dialog";
import { Field, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { actionErrorMessage } from "@/lib/errors";
import { isApiError } from "@/lib/http";
import type { DateMode, ReportFilter } from "@/modules/reporting/report-filter";
import {
    publishSnapshot,
    recomputeSnapshot,
    type ReportSnapshotSummary,
} from "@/modules/reporting/snapshots";

type Target =
    | {
          kind: "publish";
          definitionId: string;
          filter: ReportFilter;
          mode: DateMode;
      }
    | { kind: "recompute"; snapshotId: string; definitionId: string };

function SnapshotForm({
    companyId,
    target,
    onDone,
}: {
    companyId: string;
    target: Target;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [reason, setReason] = useState("");
    const [formError, setFormError] = useState<string | null>(null);
    const prefix = target.kind === "publish" ? "publish" : "recompute";

    const run = useMutation({
        mutationFn: (): Promise<ReportSnapshotSummary> =>
            target.kind === "publish"
                ? publishSnapshot(
                      companyId,
                      target.definitionId,
                      target.filter,
                      target.mode,
                      reason.trim(),
                  )
                : recomputeSnapshot(
                      companyId,
                      target.snapshotId,
                      reason.trim(),
                  ),
        onSuccess: async (snapshot) => {
            await queryClient.invalidateQueries({
                queryKey: ["companies", companyId, "report-snapshots"],
            });
            toast.success(t(`snapshots.${prefix}.done`));
            onDone();
            await navigate({
                to: "/w/$companyId/reports/$definitionId/snapshots/$snapshotId",
                params: {
                    companyId,
                    definitionId: target.definitionId,
                    snapshotId: snapshot.id,
                },
            });
        },
        // The server says why in the user's language, e.g. a data scope may not publish.
        onError: (error) =>
            setFormError(
                isApiError(error) && error.status === 422
                    ? Object.values(error.errors).flat().join(" ") ||
                          error.message
                    : actionErrorMessage(error, t),
            ),
    });

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                run.mutate();
            }}
        >
            <FieldGroup>
                <p className="text-sm text-muted-foreground">
                    {t(`snapshots.${prefix}.intro`)}
                </p>
                {formError && <FormAlert>{formError}</FormAlert>}
                <Field>
                    <FieldLabel htmlFor="snapshot-reason">
                        {t("snapshots.reason")}
                    </FieldLabel>
                    <Textarea
                        id="snapshot-reason"
                        rows={3}
                        maxLength={255}
                        autoFocus
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                    />
                </Field>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t("common.cancel")}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={run.isPending}
                        pendingLabel={t(`snapshots.${prefix}.working`)}
                    >
                        {t(`snapshots.${prefix}.action`)}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/**
 * Publishes the report on screen as a snapshot, or recalculates one, with an optional reason (RP-FR-14,
 * X-FR-03). A recalculation never touches the snapshot it started from; the new one opens afterwards.
 */
export function SnapshotDialog({
    companyId,
    target,
    onClose,
}: {
    companyId: string;
    target: Target | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const kind = target?.kind ?? "publish";

    return (
        <FormDialog
            open={target !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={t(`snapshots.${kind}.title`)}
            description={t(`snapshots.${kind}.intro`)}
        >
            {target && (
                <SnapshotForm
                    companyId={companyId}
                    target={target}
                    onDone={onClose}
                />
            )}
        </FormDialog>
    );
}

export type SnapshotTarget = Target;
