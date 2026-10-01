<?php

namespace App\Platform\Retention;

use LogicException;

/**
 * For models of financial data: `delete()` fails early with a clear message. The database trigger
 * (RetentionGuard) is the real guard and also stops raw queries; this trait only gives developers
 * the explanation before they hit it.
 */
trait Retained
{
    public static function bootRetained(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Financial data is kept for its retention period and cannot be deleted (X-FR-05).');
        });
    }
}
