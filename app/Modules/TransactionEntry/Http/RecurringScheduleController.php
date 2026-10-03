<?php

namespace App\Modules\TransactionEntry\Http;

use App\Models\User;
use App\Modules\TransactionEntry\Application\RecurringSchedules;
use App\Modules\TransactionEntry\Domain\RecurringSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecurringScheduleController
{
    /** Schedules of the company; `filter[status]` narrows them. Those with a `last_error` are the ones that are stuck. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['filter.status' => ['sometimes', 'in:active,paused,completed'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200']])['filter']['status'] ?? null;

        return RecurringScheduleResource::collection(
            RecurringSchedule::query()
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->orderBy('next_run_on')->orderBy('id')
                ->paginate($request->integer('per_page', 50))
                ->appends($request->query()),
        );
    }

    public function show(string $companyId, string $id): RecurringScheduleResource
    {
        return new RecurringScheduleResource(RecurringSchedule::query()->findOrFail($id));
    }

    /**
     * Creates a schedule that makes an entry of the transaction type on `start_date` and then every
     * `interval_count` of the `frequency` (daily, weekly, monthly, quarterly, yearly), until `end_date`.
     * The entries are left as `draft` or `submitted` (`initial_status`).
     */
    public function store(RecurringScheduleRequest $request, RecurringSchedules $schedules): JsonResponse
    {
        /** @var array{name: string, template: array<string, mixed>, transaction_type_key: string, frequency: string, interval_count?: int, start_date: string, transaction_type_version?: int|null, end_date?: string|null, initial_status?: string} $data */
        $data = $request->validated();

        return (new RecurringScheduleResource($schedules->create($data, $this->userId($request))))->response()->setStatusCode(201);
    }

    /** Replaces the template, end date, status (`active` or `paused`) and where entries are left. `lock_version` is required. */
    public function update(UpdateRecurringScheduleRequest $request, RecurringSchedules $schedules, string $companyId, string $id): RecurringScheduleResource
    {
        /** @var array{lock_version: int, name: string, template: array<string, mixed>, transaction_type_version?: int|null, end_date?: string|null, initial_status?: string, status?: string} $data */
        $data = $request->validated();

        return new RecurringScheduleResource($schedules->update($id, $data, (int) $data['lock_version']));
    }

    /** Gives up the date the schedule is stuck at, or waiting at, and goes on to the next. */
    public function skip(RecurringSchedules $schedules, string $companyId, string $id): RecurringScheduleResource
    {
        return new RecurringScheduleResource($schedules->skip($id));
    }

    private function userId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->id;
    }
}
