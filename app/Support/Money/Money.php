<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Immutable monetary amount in a single currency, always held at a fixed scale (P-08).
 *
 * The scale comes from the caller (currency configuration lives in MasterData); this type has no
 * business rules. Precision loss throws unless a rounding mode is passed explicitly.
 */
final readonly class Money
{
    private function __construct(
        public BigDecimal $amount,
        public string $currency,
    ) {}

    public static function of(BigDecimal|string|int $amount, string $currency, int $scale, RoundingMode $rounding = RoundingMode::Unnecessary): self
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Scale must not be negative.');
        }

        return new self(BigDecimal::of($amount)->toScale($scale, $rounding), $currency);
    }

    public static function zero(string $currency, int $scale): self
    {
        return self::of(0, $currency, $scale);
    }

    public function scale(): int
    {
        return $this->amount->getScale();
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    /** Multiply by a factor (for example an exchange rate) and round back to this scale. */
    public function multipliedBy(BigDecimal|string|int $factor, RoundingMode $rounding): self
    {
        return new self($this->amount->multipliedBy($factor)->toScale($this->amount->getScale(), $rounding), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount->isEqualTo($other->amount);
    }

    /** Decimal string for API output, for example "1500.00". */
    public function toString(): string
    {
        return $this->amount->toString();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} and {$other->currency}.");
        }
    }
}
