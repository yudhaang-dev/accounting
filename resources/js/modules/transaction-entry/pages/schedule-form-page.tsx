import { useSuspenseQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { ScheduleForm } from '@/modules/transaction-entry/components/schedule-form';
import { scheduleQuery } from '@/modules/transaction-entry/recurring-schedules';

function EditSchedule({
    companyId,
    scheduleId,
    canUseDimensions,
}: {
    companyId: string;
    scheduleId: string;
    canUseDimensions: boolean;
}) {
    const { t } = useTranslation();
    const { data: schedule } = useSuspenseQuery(
        scheduleQuery(companyId, scheduleId),
    );

    useDocumentTitle(t('schedules.form.editTitle', { name: schedule.name }));

    return (
        <Card>
            <CardHeader>
                <CardTitle role="heading" aria-level={1}>
                    {t('schedules.form.editTitle', { name: schedule.name })}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ScheduleForm
                    companyId={companyId}
                    schedule={schedule}
                    canUseDimensions={canUseDimensions}
                />
            </CardContent>
        </Card>
    );
}

/** A new schedule (no `scheduleId`) or the edit of an existing one. */
export function ScheduleFormPage({
    companyId,
    scheduleId,
    canUseDimensions,
}: {
    companyId: string;
    scheduleId?: string;
    canUseDimensions: boolean;
}) {
    const { t } = useTranslation();

    useDocumentTitle(t('schedules.form.newTitle'));

    if (scheduleId) {
        return (
            <EditSchedule
                companyId={companyId}
                scheduleId={scheduleId}
                canUseDimensions={canUseDimensions}
            />
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle role="heading" aria-level={1}>
                    {t('schedules.form.newTitle')}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ScheduleForm
                    companyId={companyId}
                    canUseDimensions={canUseDimensions}
                />
            </CardContent>
        </Card>
    );
}
