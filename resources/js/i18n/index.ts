import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { en } from './en';
import { id } from './id';

export const LOCALES = ['id', 'en'] as const;
export type Locale = (typeof LOCALES)[number];

const STORAGE_KEY = 'locale';
const DEFAULT_LOCALE: Locale = 'id';

declare module 'i18next' {
    interface CustomTypeOptions {
        defaultNS: 'translation';
        resources: { translation: typeof id };
    }
}

function isLocale(value: unknown): value is Locale {
    return LOCALES.includes(value as Locale);
}

function storedLocale(): Locale {
    try {
        const value = localStorage.getItem(STORAGE_KEY);

        return isLocale(value) ? value : DEFAULT_LOCALE;
    } catch {
        // Storage can be blocked (private mode); the default is fine.
        return DEFAULT_LOCALE;
    }
}

function syncDocument(locale: Locale): void {
    document.documentElement.lang = locale;
}

/** Switch the UI language; also drives the Accept-Language header of every API call. */
export async function setLocale(locale: Locale): Promise<void> {
    try {
        localStorage.setItem(STORAGE_KEY, locale);
    } catch {
        // Not persisting is acceptable.
    }

    syncDocument(locale);
    await i18n.changeLanguage(locale);
}

export function initI18n(): typeof i18n {
    const locale = storedLocale();

    syncDocument(locale);

    void i18n.use(initReactI18next).init({
        resources: { id: { translation: id }, en: { translation: en } },
        lng: locale,
        fallbackLng: DEFAULT_LOCALE,
        interpolation: { escapeValue: false },
        returnNull: false,
    });

    return i18n;
}

export { i18n };
