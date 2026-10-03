<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Platform\Numbering\Numbering;
use App\Platform\Numbering\NumberSeries;
use App\Platform\Numbering\NumberSeriesNotFound;
use App\Platform\Numbering\ResetPeriod;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Issues entry numbers from the series of a transaction type, creating the series on first use (TE-FR-16).
 * The numbers of an entry are unique across the types of a company, so two types never share a prefix:
 * the default prefix comes from the key of the type, and a series a company defines must not reuse the
 * prefix of another type.
 *
 * @phpstan-type Series array{pattern: string, padding: int, reset: string}
 */
final class EntryNumbers
{
    private const SERIES_PREFIX = 'entry:';

    public function __construct(
        private readonly Numbering $numbering,
        private readonly CompanyContext $company,
    ) {}

    public function next(string $typeKey, CarbonImmutable $date): string
    {
        $companyId = $this->company->require();
        $series = self::SERIES_PREFIX.$typeKey;

        try {
            return $this->numbering->next($companyId, $series, $date);
        } catch (NumberSeriesNotFound) {
            $default = $this->defaultSeries($typeKey);

            $this->numbering->define($companyId, $series, $default['pattern'], $default['padding'], ResetPeriod::from($default['reset']));

            return $this->numbering->next($companyId, $series, $date);
        }
    }

    /**
     * The series of a type as it applies now: the one the company defined, or the default it gets on first use.
     *
     * @return Series
     */
    public function describe(string $typeKey): array
    {
        $series = NumberSeries::query()->where('company_id', $this->company->require())->where('key', self::SERIES_PREFIX.$typeKey)->first();

        return $series === null
            ? $this->defaultSeries($typeKey)
            : ['pattern' => $series->pattern, 'padding' => $series->padding, 'reset' => $series->reset_period->value];
    }

    /**
     * Sets the series of a type and when its entries are numbered. Numbers already issued keep their text.
     *
     * @throws ValidationException when the pattern is not valid or its prefix belongs to another type
     */
    public function configure(TransactionType $type, string $pattern, int $padding, ResetPeriod $reset, string $policy): void
    {
        $prefix = $this->literalPrefix($pattern);

        if ($prefix === '') {
            throw ValidationException::withMessages(['pattern' => [__('entries.number_prefix_required')]]);
        }

        if ($this->prefixesOfOtherTypes($type)->contains($prefix)) {
            throw ValidationException::withMessages(['pattern' => [__('entries.number_prefix_taken', ['prefix' => $prefix])]]);
        }

        try {
            $this->numbering->define($this->company->require(), self::SERIES_PREFIX.$type->key, $pattern, $padding, $reset);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['pattern' => [$e->getMessage()]]);
        }

        $type->forceFill(['number_policy' => $policy])->save();
    }

    /** @return Series */
    private function defaultSeries(string $typeKey): array
    {
        /** @var array{padding: int, reset: string} $default */
        $default = config('entries.number');

        return ['pattern' => $this->defaultPrefix($typeKey).'/{yyyy}/{mm}/{seq}', ...$default];
    }

    private function defaultPrefix(string $typeKey): string
    {
        $reserved = array_values(config('entries.system_number_prefixes'));
        $prefix = config('entries.system_number_prefixes')[$typeKey] ?? strtoupper(str_replace('_', '-', $typeKey));

        // A company key such as `rev` must not take the prefix of a system type.
        return in_array($prefix, $reserved, true) && ! array_key_exists($typeKey, config('entries.system_number_prefixes')) ? "{$prefix}-1" : $prefix;
    }

    /**
     * What the other types of the company are numbered under: their own series, or the default they get on first use.
     *
     * @return Collection<int, string>
     */
    private function prefixesOfOtherTypes(TransactionType $type): Collection
    {
        $series = NumberSeries::query()->where('company_id', $this->company->require())->where('key', 'like', self::SERIES_PREFIX.'%')->pluck('pattern', 'key');

        return TransactionType::query()->where('id', '!=', $type->id)->pluck('key')->map(function (string $key) use ($series): string {
            $pattern = $series[self::SERIES_PREFIX.$key] ?? $this->defaultSeries($key)['pattern'];

            return $this->literalPrefix($pattern);
        });
    }

    /** The text before the first token, without the separator that ends it: "JU/{yyyy}/{seq}" gives "JU". */
    private function literalPrefix(string $pattern): string
    {
        return rtrim(strstr($pattern, '{', true) ?: '', "/-_. \t");
    }
}
