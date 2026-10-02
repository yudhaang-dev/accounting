<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Application\ImportExchangeRates;
use App\Modules\MasterData\Application\RecordExchangeRate;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Modules\MasterData\Domain\ExchangeRate;
use App\Modules\MasterData\Domain\RateType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ExchangeRateController
{
    /**
     * Exchange rates, newest effective date first. By default only the current version of each rate
     * is listed. Filters: `filter[rate_type]` (key), `filter[currency]`, `filter[from]` and
     * `filter[to]` (effective date), and `filter[include_superseded]=1` to list every version.
     * `per_page` defaults to 50, at most 200.
     */
    public function index(ListExchangeRatesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);

        $rates = ExchangeRate::query()
            ->with('rateType')
            ->when(isset($filters['rate_type']), fn (Builder $query) => $query->whereIn('rate_type_id', RateType::query()->where('key', $filters['rate_type'])->select('id')))
            ->when(isset($filters['currency']), fn (Builder $query) => $query->where('currency', $filters['currency']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('effective_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('effective_date', '<=', $filters['to']))
            ->unless($filters['include_superseded'] ?? false, fn (Builder $query) => $query->whereNotExists(
                fn ($newer) => $newer->from('md_exchange_rates as newer')
                    ->whereColumn('newer.company_id', 'md_exchange_rates.company_id')
                    ->whereColumn('newer.rate_type_id', 'md_exchange_rates.rate_type_id')
                    ->whereColumn('newer.currency', 'md_exchange_rates.currency')
                    ->whereColumn('newer.effective_date', 'md_exchange_rates.effective_date')
                    ->whereColumn('newer.version', '>', 'md_exchange_rates.version'),
            ))
            ->orderByDesc('effective_date')
            ->orderBy('currency')
            ->orderByDesc('version')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return ExchangeRateResource::collection($rates);
    }

    public function show(string $companyId, string $id): ExchangeRateResource
    {
        return new ExchangeRateResource(ExchangeRate::query()->with('rateType')->findOrFail($id));
    }

    /**
     * The rate that applies on `date`: the latest version of the rate with the latest effective date
     * on or before it, for the rate type and currency. Answers `EXCHANGE_RATE_NOT_FOUND` when there is
     * none or the newest one is older than the age limit of the rate type; it never falls back to 1,
     * another rate type or a cross rate (MD-FR-08).
     *
     * @response array{data: array{id: string, rate_type: string, currency: string, requested_date: string, effective_date: string, rate: string, version: int}}
     */
    public function lookup(LookupExchangeRateRequest $request, ExchangeRates $rates, string $companyId): JsonResponse
    {
        /** @var array{rate_type: string, currency: string, date: string} $params */
        $params = $request->validated();

        $found = $rates->find($companyId, $params['rate_type'], $params['currency'], $params['date']);

        return new JsonResponse(['data' => [
            'id' => $found->id,
            'rate_type' => $found->rateTypeKey,
            'currency' => $found->currency,
            'requested_date' => $params['date'],
            'effective_date' => $found->effectiveDate,
            'rate' => $found->rate,
            'version' => $found->version,
        ]]);
    }

    /**
     * Records the rate of a foreign currency for a rate type and an effective date, as functional
     * currency per 1 unit. A different rate for the same type, currency and date is recorded as a
     * new version (a correction); the same rate again changes nothing and answers 200 with the
     * existing version. The functional currency and currencies that are not activated are refused.
     */
    public function store(RecordExchangeRateRequest $request, RecordExchangeRate $record): JsonResponse
    {
        /** @var array{rate_type: string, currency: string, effective_date: string, rate: string} $data */
        $data = $request->validated();

        $result = $record($data, RecordExchangeRate::sourceOfCurrentActor());

        return (new ExchangeRateResource($result['rate']->load('rateType')))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    /**
     * Imports rates from CSV, as a multipart `file` or a `text/csv` body. Columns: `rate_type`,
     * `currency`, `effective_date`, `rate`. At most 1000 rows. All or nothing: any invalid row
     * rejects the whole file and every problem is listed under `rows.<line>.<field>`.
     *
     * @response array{data: array{created: int, unchanged: int}}
     */
    public function import(Request $request, ImportExchangeRates $import): JsonResponse
    {
        $file = $request->file('file');

        if ($file !== null) {
            $csv = $file->getContent();
        } elseif (str_contains((string) $request->header('Content-Type'), 'text/csv')) {
            $csv = $request->getContent();
        } else {
            throw ValidationException::withMessages(['file' => [__('rates.csv_required')]]);
        }

        if (strlen($csv) > 1024 * 1024) {
            throw ValidationException::withMessages(['file' => [__('rates.csv_too_large')]]);
        }

        return new JsonResponse(['data' => $import($csv)], 201);
    }
}
