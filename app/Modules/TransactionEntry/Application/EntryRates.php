<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Company\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Currencies and rates of an entry (TE-FR-06): which currency a version accepts, whether the entry may
 * bring its own rate, and which rate is used otherwise. A rate is never assumed: with no input and no
 * rate in the table the entry cannot be submitted.
 */
final class EntryRates
{
    private ?string $functional = null;

    public function __construct(
        private readonly CompanyContext $company,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
        private readonly ExchangeRates $rates,
    ) {}

    /** The currency when it is a foreign one, null for the functional currency or none. */
    public function foreign(?string $currency): ?string
    {
        return $currency === null || $currency === $this->functionalCurrency() ? null : $currency;
    }

    /**
     * Checks the currency and the rate of an entry or a line against the version. The messages are keyed
     * by `$currencyKey` and `$rateKey`, the names of the input fields.
     *
     * @throws ValidationException
     */
    public function assertAllowed(TransactionTypeVersion $version, ?string $currency, ?string $inputRate, string $currencyKey, string $rateKey): void
    {
        $errors = [];
        $foreign = $this->foreign($currency);

        if ($foreign === null) {
            if ($inputRate !== null) {
                $errors[$rateKey] = [__('entries.rate_not_applicable')];
            }
        } else {
            if ($version->allowed_currencies !== null && ! in_array($foreign, $version->allowed_currencies, true)) {
                $errors[$currencyKey] = [__('entries.currency_not_allowed', ['currency' => $foreign])];
            } elseif (! $this->currencies->isActive($this->company->require(), $foreign)) {
                $errors[$currencyKey] = [__('entries.currency_inactive', ['currency' => $foreign])];
            }

            if ($inputRate !== null) {
                if (! $version->allow_rate_input) {
                    $errors[$rateKey] = [__('entries.rate_input_not_allowed')];
                } elseif (preg_match('/^\d+(\.\d{1,12})?$/', $inputRate) !== 1 || BigDecimal::of($inputRate)->isZero()) {
                    $errors[$rateKey] = [__('entries.rate_invalid')];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The rate an entry uses for a foreign currency on the accounting date: the one it brought, or else
     * the latest in the rate type of the version, falling back to the default of the system. `rate_type_key`
     * is set when the rate comes from the table. When `$strict` is false a missing rate gives null, for a
     * draft that has not got one yet.
     *
     * @return array{rate: string, rate_type_key: string|null}|null
     *
     * @throws ApiException EXCHANGE_RATE_NOT_FOUND
     */
    public function resolve(TransactionTypeVersion $version, string $currency, ?string $inputRate, string $date, bool $strict): ?array
    {
        if ($inputRate !== null) {
            return ['rate' => $inputRate, 'rate_type_key' => null];
        }

        $key = $version->rate_type_key ?? (string) config('entries.default_rate_type');

        try {
            return ['rate' => $this->rates->find($this->company->require(), $key, $currency, $date)->rate, 'rate_type_key' => $key];
        } catch (ApiException $e) {
            if ($strict || $e->errorCode !== ErrorCode::ExchangeRateNotFound) {
                throw $e;
            }

            return null;
        }
    }

    /** Decimal places of amounts in the currency of an entry: the foreign currency, or the functional one when there is none. */
    public function scaleOf(?string $currency): int
    {
        $companyId = $this->company->require();
        $foreign = $this->foreign($currency);

        return $foreign === null ? $this->currencies->functionalScale($companyId) : ($this->currencies->find($companyId, $foreign)->scale ?? $this->currencies->functionalScale($companyId));
    }

    /** An amount in a foreign currency in the functional currency, rounded as the ledger rounds it (GL-FR-08). */
    public function toFunctional(BigDecimal $amount, string $rate): BigDecimal
    {
        $companyId = $this->company->require();
        $rounding = $this->currencies->find($companyId, $this->functionalCurrency())->rounding ?? CurrencyRounding::HalfUp;

        return $amount->multipliedBy($rate)->toScale($this->currencies->functionalScale($companyId), $rounding->brick());
    }

    private function functionalCurrency(): string
    {
        return $this->functional ??= $this->companies->find($this->company->require())->functionalCurrency ?? '';
    }
}
