import { Link } from '@tanstack/react-router';
import { BookOpenCheckIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { LanguageSwitcher } from './language-switcher';

type Props = {
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
};

/**
 * Shared frame of the signed-out screens. The card eases in on every screen change, and the
 * motion is dropped for people who ask their system for reduced motion.
 */
export function AuthLayout({ title, description, children, footer }: Props) {
    const { t } = useTranslation();

    return (
        <main className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted/40 p-4">
            <Link
                to="/"
                className="flex items-center gap-2 text-sm font-medium text-foreground/80 transition-colors hover:text-foreground"
            >
                <BookOpenCheckIcon className="size-5" aria-hidden />
                {t('common.appName')}
            </Link>

            <Card className="w-full max-w-sm duration-300 motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2">
                <CardHeader>
                    <CardTitle role="heading" aria-level={1}>
                        {title}
                    </CardTitle>
                    {description && (
                        <CardDescription>{description}</CardDescription>
                    )}
                </CardHeader>
                <CardContent>{children}</CardContent>
                {footer && (
                    <CardFooter className="justify-center text-sm">
                        {footer}
                    </CardFooter>
                )}
            </Card>

            <LanguageSwitcher />
        </main>
    );
}
