<?php

namespace App\Platform\Api\Concerns;

use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;

/**
 * Optimistic concurrency for mutable resources (API-TR-14, API-AC-09).
 *
 * The table has `lock_version` (unsigned integer, default 1) and the resource exposes it. API updates
 * go through `updateLocked()`, which only writes if nobody changed the row since the client read it.
 * Plain `save()` calls also bump the version, so stale clients conflict instead of overwriting.
 *
 * @property int $lock_version
 */
trait HasLockVersion
{
    public static function bootHasLockVersion(): void
    {
        static::updating(function ($model): void {
            if ($model->isDirty() && ! $model->isDirty('lock_version')) {
                $model->lock_version = (int) $model->getOriginal('lock_version') + 1;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ApiException VERSION_CONFLICT when the stored version differs from `$expectedVersion`
     */
    public function updateLocked(array $attributes, int $expectedVersion): void
    {
        if ((int) $this->lock_version !== $expectedVersion) {
            throw new ApiException(ErrorCode::VersionConflict);
        }

        $this->fill($attributes);
        $changes = $this->getDirty();

        if ($changes === []) {
            return;
        }

        $this->lock_version = $expectedVersion + 1;
        $changes['lock_version'] = $expectedVersion + 1;

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
            $changes[$this->getUpdatedAtColumn()] = $this->getAttribute($this->getUpdatedAtColumn());
        }

        // Compare-and-set in one statement closes the race between the check above and the write.
        $updated = $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->where('lock_version', $expectedVersion)
            ->update($changes);

        if ($updated !== 1) {
            $this->lock_version = $expectedVersion;
            $this->syncOriginal();

            throw new ApiException(ErrorCode::VersionConflict);
        }

        $this->syncChanges()->syncOriginal();
    }
}
