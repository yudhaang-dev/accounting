<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Application\CreateFiscalYears;
use App\Modules\GeneralLedger\Domain\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FiscalYearController
{
    /**
     * Fiscal years of the company in date order, with their number of periods.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return FiscalYearResource::collection(
            FiscalYear::query()->withCount('periods')->orderBy('start_date')->paginate(min(max($request->integer('per_page', 50), 1), 200)),
        );
    }

    /**
     * A fiscal year with all its periods.
     */
    public function show(string $companyId, string $id): FiscalYearResource
    {
        return new FiscalYearResource(FiscalYear::query()->with('periods')->findOrFail($id));
    }

    /**
     * Creates `count` fiscal years (default 1), each with twelve monthly periods. The first year
     * needs `start_date` (the 1st of the calendar start month); later years continue the previous
     * one. Answers 422 `MAPPING_REQUIRED` while the fiscal calendar has not been set up.
     */
    public function store(CreateFiscalYearsRequest $request, CreateFiscalYears $createYears): JsonResponse
    {
        $years = $createYears(
            $request->validated('start_date'),
            (int) $request->validated('count', 1),
            $request->validated('name'),
        );

        return FiscalYearResource::collection($years)->response()->setStatusCode(201);
    }
}
