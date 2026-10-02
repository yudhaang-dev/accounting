<?php

namespace App\Platform\Api;

/**
 * A string map that always serializes as a JSON object. PHP encodes an empty array as `[]`, but the
 * API promises `{}` for "no entries" (for example the dimensions of a line), so the map is handed to
 * the response as an object. The declared type is the map, which is what the API specification shows.
 */
final class JsonMap
{
    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    public static function of(array $map)
    {
        // @phpstan-ignore return.type
        return (object) $map;
    }
}
