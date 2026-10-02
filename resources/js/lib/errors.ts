import type { TFunction } from 'i18next';
import { isApiError } from '@/lib/http';

/**
 * Text for a failure that has no screen-specific meaning: no connection, or something unexpected.
 * Screens handle the cases they understand first (wrong password, invalid code, throttling).
 */
export function genericErrorMessage(error: unknown, t: TFunction): string {
    if (isApiError(error) && error.isNetwork) {
        return t('common.offline');
    }

    return t('common.unexpected');
}

/**
 * Text for a failure of an action: what the server said when it answered (already in the user's
 * language, e.g. why a value cannot be deleted), or the generic text when it did not.
 */
export function actionErrorMessage(error: unknown, t: TFunction): string {
    return isApiError(error) && error.status !== 0
        ? error.message
        : genericErrorMessage(error, t);
}
