<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\Company;
use App\Modules\MasterData\Domain\CompanyCurrency;
use App\Modules\MasterData\Domain\ExchangeRate;
use App\Modules\MasterData\Domain\RateSource;
use App\Modules\MasterData\Domain\RateType;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Records the rate of a foreign currency for a rate type and an effective date (MD-FR-07). Rates are
 * never changed: recording a different rate for the same type, currency and date adds a new version
 * (a correction), and recording the same rate again changes nothing.
 */
final class RecordExchangeRate
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'rate_type' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:32'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            // Up to 12 integer and 12 decimal digits, greater than zero, as a decimal string (API-TR-05).
            'rate' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,12})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ];
    }

    /**
     * How the rate is attributed: a person using the application is `manual`; an integration client
     * calling the same endpoint is `api`.
     */
    public static function sourceOfCurrentActor(): RateSource
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $actor = Auth::user();

        return $actor === null || $actor instanceof $userModel ? RateSource::Manual : RateSource::Api;
    }

    /**
     * @param  array{rate_type: string, currency: string, effective_date: string, rate: string}  $data
     * @return array{rate: ExchangeRate, created: bool} `created` is false when the latest version already has this rate
     *
     * @throws ValidationException
     */
    public function __invoke(array $data, RateSource $source): array
    {
        $data = Validator::make($data, self::rules())->validate();

        return DB::transaction(function () use ($data, $source): array {
            $type = RateType::query()->where('key', $data['rate_type'])->first()
                ?? throw ValidationException::withMessages(['rate_type' => [__('rates.type_unknown', ['key' => $data['rate_type']])]]);

            if (! $type->isActive()) {
                throw ValidationException::withMessages(['rate_type' => [__('rates.type_inactive', ['type' => $type->label])]]);
            }

            $this->assertForeignCurrency($data['currency']);

            // Serialises concurrent writers of the same rate, so versions never collide.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [implode('|', [$this->context->require(), $type->id, $data['currency'], $data['effective_date']])]);

            $latest = ExchangeRate::query()
                ->where('rate_type_id', $type->id)
                ->where('currency', $data['currency'])
                ->whereDate('effective_date', $data['effective_date'])
                ->orderByDesc('version')
                ->first();

            $rate = BigDecimal::of($data['rate']);

            if ($latest !== null && $latest->rate->isEqualTo($rate)) {
                return ['rate' => $latest, 'created' => false];
            }

            $actor = Auth::user();

            $created = ExchangeRate::create([
                'rate_type_id' => $type->id,
                'currency' => $data['currency'],
                'effective_date' => $data['effective_date'],
                'rate' => $rate,
                'version' => $latest === null ? 1 : $latest->version + 1,
                'source' => $source,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor === null ? null : (string) $actor->getAuthIdentifier(),
            ])->refresh();

            $this->audit->record(
                $latest === null ? 'exchange_rate.recorded' : 'exchange_rate.corrected',
                "Rate {$type->key} {$created->currency} {$data['effective_date']} recorded as version {$created->version}.",
                $created,
                before: $latest === null ? [] : ['rate' => ExchangeRate::format($latest->rate), 'version' => $latest->version],
                after: ['rate' => ExchangeRate::format($created->rate), 'version' => $created->version, 'source' => $source->value],
                companyId: $created->company_id,
            );

            return ['rate' => $created, 'created' => true];
        });
    }

    /**
     * Rates exist for activated foreign currencies only: the functional currency needs none.
     *
     * @throws ValidationException
     */
    private function assertForeignCurrency(string $currency): void
    {
        if (Company::query()->findOrFail($this->context->require())->functional_currency === $currency) {
            throw ValidationException::withMessages(['currency' => [__('rates.functional_currency', ['code' => $currency])]]);
        }

        $activated = CompanyCurrency::query()->where('currency', $currency)->first();

        if ($activated === null || ! $activated->isActive()) {
            throw ValidationException::withMessages(['currency' => [__('rates.currency_not_active', ['code' => $currency])]]);
        }
    }
}
