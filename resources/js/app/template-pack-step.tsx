import {
    useMutation,
    useQueryClient,
    useSuspenseQuery,
} from '@tanstack/react-query';
import { Suspense, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    installPack,
    templatePackQuery,
    type InstallResult,
    type Pack,
    type PackComponent,
    type PackSummary,
} from '@/app/template-packs';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Skeleton } from '@/components/ui/skeleton';
import { genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';

/** An item is named by its component too: two components may use the same key. */
const itemId = (component: string, key: string) => `${component}\u0000${key}`;

/** First message of a 422, which names the component or item at fault. */
const firstMessage = (error: unknown): string | undefined =>
    isApiError(error)
        ? (Object.values(error.errors)[0]?.[0] ?? error.message)
        : undefined;

function ComponentReview({
    component,
    picked,
    leftOut,
    onPick,
    onToggleItem,
}: {
    component: PackComponent;
    picked: boolean;
    leftOut: ReadonlySet<string>;
    onPick: (picked: boolean) => void;
    onToggleItem: (key: string, install: boolean) => void;
}) {
    const { t } = useTranslation();
    const creating = component.items.filter(
        (item) =>
            !item.exists &&
            item.conflict === null &&
            !leftOut.has(itemId(component.id, item.key)),
    ).length;
    const conflicts = component.items.filter(
        (item) => item.conflict !== null && !item.exists,
    ).length;

    return (
        <li className="rounded-lg border">
            <div className="flex items-center gap-3 p-3">
                <Checkbox
                    id={`pack-component-${component.id}`}
                    checked={picked}
                    aria-label={t('setup.packs.installComponent', {
                        name: component.name,
                    })}
                    onCheckedChange={(value) => onPick(value === true)}
                />
                <div className="flex min-w-0 flex-1 flex-col">
                    <span className="font-medium">
                        {t(`setup.packs.kinds.${component.kind}`, {
                            defaultValue: component.kind,
                        })}
                        <span className="font-normal text-muted-foreground">
                            {' · '}
                            {component.name}
                        </span>
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {picked
                            ? t('setup.packs.componentSummary', {
                                  create: creating,
                                  total: component.items.length,
                              })
                            : t('setup.packs.componentSkipped')}
                    </span>
                </div>
                {conflicts > 0 && (
                    <Badge variant="destructive">
                        {t('setup.packs.conflicts', { count: conflicts })}
                    </Badge>
                )}
            </div>

            {component.items.length > 0 && (
                <details className="border-t">
                    <summary className="cursor-pointer px-3 py-2 text-sm text-muted-foreground">
                        {t('setup.packs.showItems', {
                            count: component.items.length,
                        })}
                    </summary>
                    <ul className="max-h-72 overflow-auto px-3 pb-3 text-sm">
                        {component.items.map((item) => {
                            const stuck = item.exists || item.conflict !== null;
                            const isIncluded =
                                !stuck &&
                                !leftOut.has(itemId(component.id, item.key));

                            return (
                                <li
                                    key={item.key}
                                    className={`flex items-start gap-2 py-1 ${
                                        isIncluded && picked
                                            ? ''
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    <Checkbox
                                        checked={isIncluded}
                                        disabled={
                                            !picked || stuck || !item.optional
                                        }
                                        aria-label={t(
                                            'setup.packs.includeItem',
                                            { label: item.label },
                                        )}
                                        onCheckedChange={(value) =>
                                            onToggleItem(
                                                item.key,
                                                value === true,
                                            )
                                        }
                                    />
                                    <span className="flex-1">
                                        {item.label}
                                        {item.exists && (
                                            <Badge
                                                variant="secondary"
                                                className="ml-2"
                                            >
                                                {t('setup.packs.exists')}
                                            </Badge>
                                        )}
                                        {item.optional && !item.exists && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                {t('setup.packs.optional')}
                                            </Badge>
                                        )}
                                        {item.conflict !== null &&
                                            !item.exists && (
                                                <span className="mt-0.5 block text-xs text-destructive">
                                                    {t('setup.packs.conflict', {
                                                        reason: item.conflict,
                                                    })}
                                                </span>
                                            )}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </details>
            )}
        </li>
    );
}

function PackReview({
    companyId,
    packKey,
    onInstalled,
}: {
    companyId: string;
    packKey: string;
    onInstalled: (result: InstallResult, pack: Pack) => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const { data: pack } = useSuspenseQuery(
        templatePackQuery(companyId, packKey),
    );
    const [unpicked, setUnpicked] = useState<ReadonlySet<string>>(new Set());
    const [leftOut, setLeftOut] = useState<ReadonlySet<string>>(new Set());
    const [confirmed, setConfirmed] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    const picked = pack.components.filter(
        (component) => !unpicked.has(component.id),
    );

    /** What the server is asked to leave out: what the user left out, plus every item in conflict. */
    const excludes = (): Record<string, string[]> =>
        Object.fromEntries(
            picked
                .map((component): [string, string[]] => [
                    component.id,
                    component.items
                        .filter(
                            (item) =>
                                !item.exists &&
                                (item.conflict !== null ||
                                    leftOut.has(
                                        itemId(component.id, item.key),
                                    )),
                        )
                        .map((item) => item.key),
                ])
                .filter(([, keys]) => keys.length > 0),
        );

    const install = useMutation({
        mutationFn: () =>
            installPack(companyId, pack.key, {
                components: picked.map((component) => component.id),
                exclude: excludes(),
            }),
        onSuccess: async (result) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId],
            });
            onInstalled(result, pack);
        },
        onError: (error) => {
            setFormError(
                isApiError(error) && error.status === 403
                    ? t('setup.packs.forbidden')
                    : (firstMessage(error) ?? genericErrorMessage(error, t)),
            );

            // Someone else changed the company meanwhile: show what is there now.
            if (isApiError(error) && error.status === 409) {
                void queryClient.invalidateQueries({
                    queryKey: templatePackQuery(companyId, pack.key).queryKey,
                });
            }
        },
    });

    const toggle = (
        set: ReadonlySet<string>,
        value: string,
        on: boolean,
    ): ReadonlySet<string> => {
        const next = new Set(set);

        if (on) {
            next.add(value);
        } else {
            next.delete(value);
        }

        return next;
    };

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                setFormError(null);
                install.mutate();
            }}
        >
            <FieldGroup>
                <div className="flex flex-col gap-1">
                    <h3 className="text-sm font-medium">
                        {t('setup.packs.review')}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {pack.description}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t('setup.packs.reviewHint')}
                    </p>
                </div>

                <ul className="flex flex-col gap-2">
                    {pack.components.map((component) => (
                        <ComponentReview
                            key={component.id}
                            component={component}
                            picked={!unpicked.has(component.id)}
                            leftOut={leftOut}
                            onPick={(on) =>
                                setUnpicked((current) =>
                                    toggle(current, component.id, !on),
                                )
                            }
                            onToggleItem={(key, on) =>
                                setLeftOut((current) =>
                                    toggle(
                                        current,
                                        itemId(component.id, key),
                                        !on,
                                    ),
                                )
                            }
                        />
                    ))}
                </ul>

                {formError && <FormAlert>{formError}</FormAlert>}

                <Field orientation="horizontal">
                    <Checkbox
                        id="pack-confirm"
                        checked={confirmed}
                        onCheckedChange={(value) =>
                            setConfirmed(value === true)
                        }
                    />
                    <FieldLabel htmlFor="pack-confirm" className="font-normal">
                        {t('setup.packs.confirm')}
                    </FieldLabel>
                </Field>

                <SubmitButton
                    className="w-auto self-start"
                    pending={install.isPending}
                    pendingLabel={t('setup.packs.submitting')}
                    disabled={!confirmed || picked.length === 0}
                >
                    {t('setup.packs.submit')}
                </SubmitButton>
            </FieldGroup>
        </form>
    );
}

/** What an install did, per component, once the company has its chart. */
export function PackResult({
    result,
    pack,
}: {
    result: InstallResult;
    pack: Pick<Pack, 'components'>;
}) {
    const { t } = useTranslation();
    const kinds = new Map(
        pack.components.map((component) => [component.id, component.kind]),
    );

    return (
        <div className="flex flex-col gap-2">
            <p className="font-medium">{t('setup.packs.installed')}</p>
            <ul className="list-disc ps-5 text-sm text-muted-foreground">
                {result.components.map((component) => (
                    <li key={component.id}>
                        {t(
                            `setup.packs.kinds.${kinds.get(component.id) ?? component.id}`,
                            { defaultValue: component.id },
                        )}
                        {': '}
                        {t('setup.packs.result', {
                            created: component.created.length,
                            skipped: component.skipped.length,
                        })}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Template pack step (X-AC-06, ADR-0019): choose a pack for the type of business, review what each
 * component would create, leave out what is optional, and confirm once. Nothing is created before the
 * confirmation; a failure leaves nothing behind.
 */
export function TemplatePackStep({
    companyId,
    packs,
    onInstalled,
}: {
    companyId: string;
    packs: PackSummary[];
    onInstalled: (result: InstallResult, pack: Pack) => void;
}) {
    const { t } = useTranslation();
    const [chosen, setChosen] = useState<string | null>(null);
    const selected = chosen ?? packs[0]?.key ?? null;

    return (
        <div className="flex flex-col gap-5">
            <p className="text-sm text-muted-foreground">
                {t('setup.packs.intro')}
            </p>

            <RadioGroup
                aria-label={t('setup.packs.pack')}
                value={selected ?? ''}
                onValueChange={(value) => setChosen(String(value))}
            >
                {packs.map((pack) => (
                    <div key={pack.key} className="flex items-center gap-2">
                        <RadioGroupItem
                            id={`pack-${pack.key}`}
                            value={pack.key}
                        />
                        <Label htmlFor={`pack-${pack.key}`}>{pack.name}</Label>
                    </div>
                ))}
            </RadioGroup>

            {selected && (
                <Suspense fallback={<Skeleton className="h-48 w-full" />}>
                    {/* Keyed so switching packs starts from a clean review. */}
                    <PackReview
                        key={selected}
                        companyId={companyId}
                        packKey={selected}
                        onInstalled={onInstalled}
                    />
                </Suspense>
            )}
        </div>
    );
}
