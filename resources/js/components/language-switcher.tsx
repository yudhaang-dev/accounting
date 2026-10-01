import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { LOCALES, setLocale } from '@/i18n';

export function LanguageSwitcher() {
    const { t, i18n } = useTranslation();

    return (
        <div
            role="group"
            aria-label={t('common.language')}
            className="flex items-center gap-1"
        >
            {LOCALES.map((locale) => (
                <Button
                    key={locale}
                    type="button"
                    size="xs"
                    variant={i18n.language === locale ? 'secondary' : 'ghost'}
                    aria-pressed={i18n.language === locale}
                    onClick={() => void setLocale(locale)}
                >
                    {locale.toUpperCase()}
                </Button>
            ))}
        </div>
    );
}
