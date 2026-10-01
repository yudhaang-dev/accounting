<?php

namespace App\Modules\TransactionEntry\Http;

use App\Models\User;
use App\Modules\TransactionEntry\Application\EntryDrafts;
use App\Modules\TransactionEntry\Application\EntryWorkflow;
use App\Modules\TransactionEntry\Domain\Entry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class EntryController
{
    /**
     * Entries of the company, newest accounting date first unless `sort` says otherwise. Filters:
     * `filter[status]`, `filter[transaction_type_key]`, `filter[from]` and `filter[to]` (accounting date).
     * `per_page` defaults to 50, at most 200.
     */
    public function index(ListEntriesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);
        $sort = $request->validated('sort', '-accounting_date');

        $entries = Entry::query()
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['transaction_type_key']), fn (Builder $query) => $query->where('transaction_type_key', $filters['transaction_type_key']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('accounting_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('accounting_date', '<=', $filters['to']))
            ->orderBy(ltrim($sort, '-'), Str::startsWith($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return EntryResource::collection($entries);
    }

    /** One entry with its approval trail. */
    public function show(string $companyId, string $id): EntryResource
    {
        return new EntryResource(Entry::query()->with('decisions')->findOrFail($id));
    }

    /**
     * Creates a draft entry on the latest published version of the transaction type, or on the
     * requested `transaction_type_version`. The entry number is issued now.
     */
    public function store(EntryRequest $request, EntryDrafts $drafts): JsonResponse
    {
        /** @var array{transaction_type_key: string, transaction_type_version?: int|null, accounting_date: string, document_date?: string|null, description: string, lines: list<array{account_id: string, debit?: string|null, credit?: string|null, description?: string|null}>} $data */
        $data = $request->validated();

        $entry = $drafts->create($data['transaction_type_key'], $data['transaction_type_version'] ?? null, $data, $this->userId($request));

        return (new EntryResource($entry))->response()->setStatusCode(201);
    }

    /**
     * Replaces the content of a draft. `lock_version` is required.
     */
    public function update(UpdateEntryRequest $request, EntryDrafts $drafts, string $companyId, string $id): EntryResource
    {
        /** @var array{lock_version: int, accounting_date: string, document_date?: string|null, description: string, lines: list<array{account_id: string, debit?: string|null, credit?: string|null, description?: string|null}>} $data */
        $data = $request->validated();

        return new EntryResource($drafts->update($id, $data, (int) $data['lock_version']));
    }

    /** Validates the draft against the ledger rules and sends it to approval. */
    public function submit(Request $request, EntryWorkflow $workflow, string $companyId, string $id): EntryResource
    {
        return new EntryResource($workflow->submit($id, $this->userId($request)));
    }

    public function approve(Request $request, EntryWorkflow $workflow, string $companyId, string $id): EntryResource
    {
        return new EntryResource($workflow->approve($id, $this->userId($request)));
    }

    /** Rejects a submitted entry; `reason` is required. */
    public function reject(ReasonRequest $request, EntryWorkflow $workflow, string $companyId, string $id): EntryResource
    {
        return new EntryResource($workflow->reject($id, $this->userId($request), (string) $request->validated('reason')));
    }

    /** Takes a rejected entry back to draft. */
    public function revise(Request $request, EntryWorkflow $workflow, string $companyId, string $id): EntryResource
    {
        return new EntryResource($workflow->revise($id, $this->userId($request)));
    }

    /** Cancels a draft or rejected entry; it stays readable. */
    public function cancel(ReasonRequest $request, EntryWorkflow $workflow, string $companyId, string $id): EntryResource
    {
        return new EntryResource($workflow->cancel($id, $this->userId($request), $request->validated('reason')));
    }

    /** Posts an approved entry to the ledger. Posting again returns the same journal. */
    public function post(Request $request, EntryWorkflow $workflow, string $companyId, string $id): EntryResource
    {
        return new EntryResource($workflow->post($id, $this->userId($request)));
    }

    private function userId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->id;
    }
}
