<?php

namespace App\Modules\MasterData\Contracts;

use Brick\Math\BigDecimal;

/**
 * A currency as a company uses it: its scale and rounding mode (MD-FR-06).
 */
final readonly class CurrencyConfig
{
    /**
     * @param  int<0, max>  $scale
     */
    public function __construct(
        public string $code,
        public int $scale,
        public CurrencyRounding $rounding,
        public bool $active,
    ) {}

    /** Rounds an amount to this currency's scale with its rounding mode, as a decimal string. */
    public function round(BigDecimal|string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale($this->scale, $this->rounding->brick());
    }
}
