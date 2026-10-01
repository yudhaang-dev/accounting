import { CheckIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

const STEPS = ['profile', 'chart', 'calendar', 'activate'] as const;

/**
 * Where the user is in the company setup: the four steps, those behind them ticked, the current one
 * marked. The same strip heads the form that creates the workspace (step 1) and the setup page
 * (steps 2 to 4), so they read as one wizard. On a narrow screen only the current step is named.
 */
export function WizardProgress({ current }: { current: 1 | 2 | 3 | 4 }) {
    const { t } = useTranslation();

    return (
        <nav
            aria-label={t('setup.progress.label')}
            className="flex flex-col gap-2"
        >
            <p className="text-sm text-muted-foreground">
                {t('setup.progress.step', {
                    current,
                    total: STEPS.length,
                })}
            </p>
            <ol className="flex flex-wrap items-center gap-x-3 gap-y-2">
                {STEPS.map((step, index) => {
                    const number = index + 1;
                    const state =
                        number < current
                            ? 'done'
                            : number === current
                              ? 'current'
                              : 'upcoming';

                    return (
                        <li
                            key={step}
                            aria-current={
                                state === 'current' ? 'step' : undefined
                            }
                            className="flex items-center gap-2 text-sm"
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium',
                                    state === 'done' &&
                                        'border-primary bg-primary text-primary-foreground',
                                    state === 'current' &&
                                        'border-primary text-foreground ring-2 ring-primary/30',
                                    state === 'upcoming' &&
                                        'text-muted-foreground',
                                )}
                            >
                                {state === 'done' ? (
                                    <CheckIcon className="size-3.5" />
                                ) : (
                                    number
                                )}
                            </span>
                            <span
                                className={cn(
                                    state === 'current'
                                        ? 'font-medium'
                                        : 'hidden text-muted-foreground sm:inline',
                                )}
                            >
                                {t(`setup.steps.${step}`)}
                            </span>
                            {index < STEPS.length - 1 && (
                                <span
                                    aria-hidden
                                    className="hidden h-px w-6 bg-border sm:block"
                                />
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
