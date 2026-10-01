<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Application\ConfigureFiscalCalendar;
use App\Modules\GeneralLedger\Domain\FiscalCalendar;
use Illuminate\Http\JsonResponse;

class FiscalCalendarController
{
    /**
     * The fiscal calendar of the company. Answers 404 until it has been set up.
     */
    public function show(): FiscalCalendarResource
    {
        return new FiscalCalendarResource(FiscalCalendar::query()->firstOrFail());
    }

    /**
     * Sets up the calendar: the month a fiscal year starts and how it is cut into periods. Answers
     * 409 when the company already has one.
     */
    public function store(StoreFiscalCalendarRequest $request, ConfigureFiscalCalendar $configure): JsonResponse
    {
        /** @var array{pattern: string, start_month: int} $data */
        $data = $request->validated();

        return (new FiscalCalendarResource($configure->create($data)))->response()->setStatusCode(201);
    }

    /**
     * Changes the calendar. `lock_version` is required; a real change is refused with 409 once
     * fiscal years exist.
     */
    public function update(UpdateFiscalCalendarRequest $request, ConfigureFiscalCalendar $configure): FiscalCalendarResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new FiscalCalendarResource($configure->update($data, $expected));
    }
}
