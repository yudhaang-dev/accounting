import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

/** Sets the browser tab title to "<page> · <app>", and keeps it in step with the language. */
export function useDocumentTitle(page: string): void {
    const { t } = useTranslation();
    const app = t('common.appName');

    useEffect(() => {
        document.title = `${page} · ${app}`;
    }, [page, app]);
}
