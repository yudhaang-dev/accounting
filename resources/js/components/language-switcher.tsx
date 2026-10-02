import { CheckIcon, ChevronDownIcon, GlobeIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LOCALES, setLocale, type Locale } from '@/i18n';

// A language is always named in itself, so it can be found by someone who cannot read the current one.
const NAMES: Record<Locale, string> = { id: 'Indonesia', en: 'English' };

/** Picks the language of the interface from a menu; the current one is named on the button. */
export function LanguageSwitcher() {
    const { t, i18n } = useTranslation();
    const current: Locale =
        LOCALES.find((locale) => i18n.language.startsWith(locale)) ?? 'id';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={t('common.language')}
                    />
                }
            >
                <GlobeIcon data-icon="inline-start" aria-hidden />
                {NAMES[current]}
                <ChevronDownIcon data-icon="inline-end" aria-hidden />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-40">
                <DropdownMenuGroup>
                    <DropdownMenuLabel>
                        {t('common.language')}
                    </DropdownMenuLabel>
                    {LOCALES.map((locale) => (
                        <DropdownMenuItem
                            key={locale}
                            onClick={() => void setLocale(locale)}
                        >
                            {NAMES[locale]}
                            {locale === current && (
                                <CheckIcon className="ms-auto" aria-hidden />
                            )}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
