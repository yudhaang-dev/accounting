<?php

namespace App\Modules\GeneralLedger\Domain;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An associative array in a JSON column that must always be stored as an object: the plain `array`
 * cast would write an empty map as `[]`, which the column check refuses.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class JsonObjectCast implements CastsAttributes, ComparesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return $value === null ? [] : (array) json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode((object) ($value ?? []), JSON_THROW_ON_ERROR);
    }

    /** Equal maps are equal whatever the key order, which JSON storage does not keep. */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        $normalise = function (mixed $value): array {
            $map = is_string($value) ? (array) json_decode($value, true) : (array) $value;
            ksort($map);

            return $map;
        };

        return $normalise($firstValue) === $normalise($secondValue);
    }
}
