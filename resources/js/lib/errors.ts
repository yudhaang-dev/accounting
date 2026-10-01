import type { TFunction } from 'i18next';
import { isApiError } from './http';

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
