import {
    useMutation,
    useQuery,
    useQueryClient,
    useSuspenseQuery,
} from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { CheckIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { PackResult, TemplatePackStep } from '@/app/template-pack-step';
import {
    templatePacksQuery,
    type InstallResult,
    type Pack,
} from '@/app/template-packs';
import { WizardProgress } from '@/app/wizard-progress';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { fiscalYearsQuery } from '@/modules/general-ledger/calendar';
import { CalendarStep } from '@/modules/general-ledger/components/calendar-step';
import { ChartStep } from '@/modules/general-ledger/components/chart-step';
import { chartSizeQuery } from '@/modules/general-ledger/chart';
import { workspacesQuery } from '@/modules/identity-access/workspaces';
import { CompanyProfileForm } from '@/modules/master-data/components/company-profile-form';
import { activateCompany, companyQuery } from '@/modules/master-data/company';

type State = 'done' | 'todo' | 'blocked';

function Step({
    number,
    title,
    state,
    action,
    children,
}: {
    number: number;
    title: string;
    state: State;
    action?: ReactNode;
    children?: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center gap-3">
                    <span
                        aria-hidden
                        className="flex size-7 shrink-0 items-center justify-center rounded-full border text-sm font-medium data-[state=done]:border-primary data-[state=done]:bg-primary data-[state=done]:text-primary-foreground"
                        data-state={state}
                    >
                        {state === 'done' ? (
                            <CheckIcon className="size-4" />
                        ) : (
                            number
                        )}
                    </span>
                    <CardTitle className="flex-1">{title}</CardTitle>
                    <Badge variant={state === 'done' ? 'secondary' : 'outline'}>
                        {t(`setup.state.${state}`)}
                    </Badge>
                    {action}
                </div>
            </CardHeader>
            {children && <CardContent>{children}</CardContent>}
        </Card>
    );
}

function ProfileStep({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const { data: company } = useSuspenseQuery(companyQuery(companyId));
    const [editing, setEditing] = useState(false);

    return (
        <Step
            number={1}
            title={t('setup.steps.profile')}
            state="done"
            action={
                !editing && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        {t('setup.edit')}
                    </Button>
                )
            }
        >
            {editing ? (
                <CompanyProfileForm
                    company={company}
                    onSaved={() => {
                        toast.success(t('setup.profile.saved'));
                        setEditing(false);
                    }}
                    onCancel={() => setEditing(false)}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    {company.name} · {company.functional_currency} ·{' '}
                    {company.timezone}
                </p>
            )}
        </Step>
    );
}

function ActivateStep({
    companyId,
    ready,
}: {
    companyId: string;
    ready: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const { data: company } = useSuspenseQuery(companyQuery(companyId));
    const [missing, setMissing] = useState<[string, string][] | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);

    const activate = useMutation({
        mutationFn: () => activateCompany(companyId),
        onSuccess: async () => {
            await Promise.all([
                queryClient.fetchQuery({
                    ...companyQuery(companyId),
                    staleTime: 0,
                }),
                queryClient.fetchQuery({ ...workspacesQuery, staleTime: 0 }),
            ]);
            toast.success(t('setup.activate.success', { name: company.name }));
            await navigate({
                to: '/w/$companyId',
                params: { companyId },
            });
        },
        onError: (error) => {
            if (isApiError(error) && error.code === 'COMPANY_NOT_READY') {
                setMissing(
                    Object.entries(error.errors).map(([key, messages]) => [
                        key,
                        messages[0] ?? '',
                    ]),
                );

                return;
            }

            setFormError(genericErrorMessage(error, t));
        },
        onSettled: () => setConfirming(false),
    });

    return (
        <Step
            number={4}
            title={t('setup.steps.activate')}
            state={ready ? 'todo' : 'blocked'}
        >
            <form
                className="flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    setConfirming(true);
                }}
            >
                <p className="text-sm text-muted-foreground">
                    {ready
                        ? t('setup.activate.description')
                        : t('setup.activate.blocked')}
                </p>

                {missing && (
                    <FormAlert>
                        <span className="font-medium">
                            {t('setup.activate.missing')}
                        </span>
                        <ul className="mt-1 list-disc ps-5">
                            {missing.map(([key, message]) => (
                                <li key={key}>
                                    {t(`setup.activate.requirements.${key}`, {
                                        defaultValue: key,
                                    })}
                                    : {message}
                                </li>
                            ))}
                        </ul>
                    </FormAlert>
                )}
                {formError && <FormAlert>{formError}</FormAlert>}

                <SubmitButton
                    className="w-auto self-start"
                    disabled={!ready}
                    pending={activate.isPending}
                    pendingLabel={t('setup.activate.submitting')}
                >
                    {t('setup.activate.submit')}
                </SubmitButton>
            </form>
            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={t('confirm.activateCompany.title', {
                    name: company.name,
                })}
                description={t('confirm.activateCompany.description')}
                confirmLabel={t('confirm.activateCompany.action')}
                pending={activate.isPending}
                onConfirm={() => {
                    setMissing(null);
                    setFormError(null);
                    activate.mutate();
                }}
            />
        </Step>
    );
}

/**
 * Chart step with the choice of a template pack for the type of business (X-AC-06) next to the chart
 * alone. Without a released pack, or for someone who may not install one, it is the chart step as before.
 */
function ChartSetup({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const size = useQuery(chartSizeQuery(companyId));
    const packs = useQuery(templatePacksQuery(companyId));
    // An add-on only adds to a chart that exists, so it is not a starting point for an empty company.
    const basePacks = packs.data?.filter((pack) => !pack.additive);
    const [mode, setMode] = useState<'pack' | 'chart'>('pack');
    const [installed, setInstalled] = useState<{
        result: InstallResult;
        pack: Pack;
    } | null>(null);

    if (installed) {
        return <PackResult result={installed.result} pack={installed.pack} />;
    }

    if ((size.data ?? 0) > 0 || packs.isError || basePacks?.length === 0) {
        return <ChartStep companyId={companyId} />;
    }

    if (basePacks === undefined) {
        return <Skeleton className="h-24 w-full" />;
    }

    return (
        <div className="flex flex-col gap-5">
            <div
                className="flex gap-2"
                role="group"
                aria-label={t('setup.packs.mode')}
            >
                <Button
                    type="button"
                    size="sm"
                    variant={mode === 'pack' ? 'default' : 'outline'}
                    aria-pressed={mode === 'pack'}
                    onClick={() => setMode('pack')}
                >
                    {t('setup.packs.modePack')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant={mode === 'chart' ? 'default' : 'outline'}
                    aria-pressed={mode === 'chart'}
                    onClick={() => setMode('chart')}
                >
                    {t('setup.packs.modeChart')}
                </Button>
            </div>

            {mode === 'pack' ? (
                <TemplatePackStep
                    companyId={companyId}
                    packs={basePacks}
                    onInstalled={(result, pack) =>
                        setInstalled({ result, pack })
                    }
                />
            ) : (
                <ChartStep companyId={companyId} />
            )}
        </div>
    );
}

/**
 * Setup wizard of a company in `setup` (MD-FR-01, X-AC-03): profile, chart of accounts, fiscal
 * calendar, activation. Every step's state comes from the server, so it can be left and resumed.
 */
export function CompanySetupPage({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const { data: company } = useSuspenseQuery(companyQuery(companyId));
    const chartSize = useQuery(chartSizeQuery(companyId));
    const years = useQuery(fiscalYearsQuery(companyId));

    useDocumentTitle(t('setup.pageTitle'));

    const chartDone = (chartSize.data ?? 0) > 0;
    const calendarDone = (years.data?.length ?? 0) > 0;

    return (
        <>
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('setup.title', { name: company.name })}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('setup.description')}
                </p>
            </div>

            <div className="max-w-3xl">
                <WizardProgress
                    current={!chartDone ? 2 : !calendarDone ? 3 : 4}
                />
            </div>

            <div className="flex max-w-3xl flex-col gap-4">
                <ProfileStep companyId={companyId} />
                <Step
                    number={2}
                    title={t('setup.steps.chart')}
                    state={chartDone ? 'done' : 'todo'}
                >
                    <ChartSetup companyId={companyId} />
                </Step>
                <Step
                    number={3}
                    title={t('setup.steps.calendar')}
                    state={calendarDone ? 'done' : 'todo'}
                >
                    <CalendarStep companyId={companyId} />
                </Step>
                <ActivateStep
                    companyId={companyId}
                    ready={chartDone && calendarDone}
                />
            </div>
        </>
    );
}
