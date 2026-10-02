import { useMatches, useRouter } from '@tanstack/react-router';
import type { ParseKeys } from 'i18next';
import { Fragment, type MouseEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Breadcrumb,
    BreadcrumbEllipsis,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';

/** One step of the trail. Without `href` it is shown but cannot be opened (a group, or the page itself). */
export type Crumb = {
    /** Text that comes from data, such as an entry number. */
    label?: string;
    /** Text that comes from the dictionary. */
    labelKey?: ParseKeys;
    href?: string;
};

/** What a route's `crumbs` function may read about its own match. */
export type CrumbMatch = {
    params: Record<string, string>;
    context: Record<string, unknown>;
    loaderData?: unknown;
};

/**
 * The trail to the current screen, assembled from the `staticData.crumbs` of every active route from
 * the top down. A screen gets its breadcrumbs by declaring them on its route; nothing here changes
 * when a screen is added. On a narrow screen only the last two steps show, the rest folded into "…".
 */
export function Breadcrumbs() {
    const { t } = useTranslation();
    const router = useRouter();
    const matches = useMatches();
    const crumbs = matches.flatMap(
        (match) =>
            match.staticData?.crumbs?.(match as unknown as CrumbMatch) ?? [],
    );

    if (crumbs.length === 0) {
        return null;
    }

    // A normal click moves within the app; a modified click (new tab) keeps the browser's own way.
    const open = (event: MouseEvent<HTMLAnchorElement>, href: string) => {
        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        event.preventDefault();
        router.history.push(href);
    };

    return (
        <Breadcrumb aria-label={t('breadcrumb.label')} className="min-w-0">
            <BreadcrumbList className="flex-nowrap">
                {crumbs.length > 2 && (
                    <>
                        <BreadcrumbItem className="md:hidden">
                            <BreadcrumbEllipsis />
                        </BreadcrumbItem>
                        <BreadcrumbSeparator className="md:hidden" />
                    </>
                )}
                {crumbs.map((crumb, index) => {
                    const last = index === crumbs.length - 1;
                    const folded = index < crumbs.length - 2;
                    const text =
                        crumb.label ??
                        (crumb.labelKey ? t(crumb.labelKey) : '');

                    return (
                        <Fragment key={`${index}-${text}`}>
                            {index > 0 && (
                                <BreadcrumbSeparator
                                    className={
                                        folded ? 'hidden md:flex' : undefined
                                    }
                                />
                            )}
                            <BreadcrumbItem
                                className={
                                    folded ? 'hidden md:inline-flex' : 'min-w-0'
                                }
                            >
                                {last ? (
                                    <BreadcrumbPage className="truncate">
                                        {text}
                                    </BreadcrumbPage>
                                ) : crumb.href ? (
                                    <BreadcrumbLink
                                        className="truncate"
                                        render={
                                            <a
                                                href={crumb.href}
                                                onClick={(event) =>
                                                    open(
                                                        event,
                                                        crumb.href ?? '',
                                                    )
                                                }
                                            />
                                        }
                                    >
                                        {text}
                                    </BreadcrumbLink>
                                ) : (
                                    <span className="truncate text-muted-foreground">
                                        {text}
                                    </span>
                                )}
                            </BreadcrumbItem>
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
