<?php

namespace App\Support\Access;

/**
 * Which dimension values of a company a user may see and act on (IA-FR-03): per dimension type key
 * the allowed value IDs, each value already expanded with all its descendants. A row is inside the
 * scope when, for every restricted type, it carries one of the allowed values; a row without a value
 * for a restricted type is outside it. Unrestricted is the default.
 *
 * Small technical type shared by IdentityAccess, which builds it, and the business modules, which
 * apply it as a filter (ADR-0002).
 */
final readonly class DataScope
{
    /** @param array<string, list<string>>|null $allowed null means unrestricted */
    private function __construct(private ?array $allowed) {}

    public static function unrestricted(): self
    {
        return new self(null);
    }

    /**
     * @param  array<string, list<string>>  $allowed  dimension type key => allowed value IDs
     */
    public static function restrictedTo(array $allowed): self
    {
        return $allowed === [] ? self::unrestricted() : new self($allowed);
    }

    public function isRestricted(): bool
    {
        return $this->allowed !== null;
    }

    /** @return array<string, list<string>> empty when unrestricted */
    public function allowed(): array
    {
        return $this->allowed ?? [];
    }

    /**
     * @param  array<string, string|null>  $dimensions  dimension type key => value ID of one row
     */
    public function allows(array $dimensions): bool
    {
        foreach ($this->allowed() as $typeKey => $valueIds) {
            $valueId = $dimensions[$typeKey] ?? null;

            if ($valueId === null || ! in_array($valueId, $valueIds, true)) {
                return false;
            }
        }

        return true;
    }
}
