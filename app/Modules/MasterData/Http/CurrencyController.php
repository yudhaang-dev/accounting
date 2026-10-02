<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Application\ActivateCurrency;
use App\Modules\MasterData\Application\SetCurrencyStatus;
use App\Modules\MasterData\Application\UpdateCurrency;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Domain\CompanyCurrency;
use App\Modules\MasterData\Domain\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CurrencyController
{
    public function __construct(private readonly CompanyDirectory $companies) {}

    /**
     * Currencies the company has activated, functional currency first. Filters: `filter[status]` and
     * `filter[search]` (code prefix or part of the name). `per_page` defaults to 50, at most 200.
     */
    public function index(ListCurrenciesRequest $request, string $companyId): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);
        $functional = $this->functionalCurrency($companyId);

        $currencies = CompanyCurrency::query()
            ->with('reference')
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['search']), fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('currency', 'ilike', $filters['search'].'%')
                    ->orWhereIn('currency', Currency::query()->where('name', 'ilike', '%'.$filters['search'].'%')->select('code')),
            ))
            ->orderByRaw('currency = ? desc', [$functional])
            ->orderBy('currency')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        $currencies->getCollection()->each(fn (CompanyCurrency $currency) => $this->flag($currency, $functional));

        return CurrencyResource::collection($currencies);
    }

    public function show(string $companyId, string $code): CurrencyResource
    {
        return $this->resource($this->find($code), $companyId);
    }

    /**
     * Activates a currency from the ISO 4217 reference. `scale` defaults to the ISO minor unit and
     * `rounding_mode` to `half_up`.
     */
    public function store(ActivateCurrencyRequest $request, ActivateCurrency $activate, string $companyId): JsonResponse
    {
        /** @var array{currency: string, scale?: int|null, rounding_mode?: string|null} $data */
        $data = $request->validated();

        return $this->resource($activate($data)->load('reference'), $companyId)->response()->setStatusCode(201);
    }

    /**
     * Changes the scale or rounding mode. `lock_version` is required; the scale of the functional
     * currency can only change while the company is in `setup`.
     */
    public function update(UpdateCurrencyRequest $request, UpdateCurrency $update, string $companyId, string $code): CurrencyResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return $this->resource($update($code, $data, $expected)->load('reference'), $companyId);
    }

    /**
     * Deactivates the currency. The functional currency is always active.
     */
    public function deactivate(SetCurrencyStatus $status, string $companyId, string $code): CurrencyResource
    {
        return $this->resource($status->deactivate($code)->load('reference'), $companyId);
    }

    /**
     * Reactivates the currency.
     */
    public function reactivate(SetCurrencyStatus $status, string $companyId, string $code): CurrencyResource
    {
        return $this->resource($status->reactivate($code)->load('reference'), $companyId);
    }

    private function find(string $code): CompanyCurrency
    {
        return CompanyCurrency::query()->with('reference')->where('currency', $code)->firstOrFail();
    }

    private function resource(CompanyCurrency $currency, string $companyId): CurrencyResource
    {
        $this->flag($currency, $this->functionalCurrency($companyId));

        return new CurrencyResource($currency);
    }

    private function flag(CompanyCurrency $currency, string $functional): void
    {
        $currency->setAttribute('is_functional', $currency->currency === $functional);
    }

    private function functionalCurrency(string $companyId): string
    {
        return ($this->companies->find($companyId) ?? throw new NotFoundHttpException)->functionalCurrency;
    }
}
