<?php

namespace App\Modules\TransactionEntry\Http;

use App\Models\User;
use App\Modules\TransactionEntry\Application\EntryReversals;
use Illuminate\Http\JsonResponse;

class JournalReversalController
{
    /**
     * Asks for the reversal of a posted journal (TE-FR-11): creates a draft entry of the type
     * `system.reversal` and returns it. Submit, approve and post it like any entry; the policy decides
     * who must approve. A journal is reversed in full and only once; 404 for a journal that does not
     * exist or that the caller does not see entirely, 409 when it was reversed already or a request is
     * under way, 422 when the period of `reversal_date` does not accept the posting.
     */
    public function store(ReverseJournalRequest $request, EntryReversals $reversals, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $entry = $reversals->request($id, $request->string('reversal_date')->toString(), $request->string('reason')->toString(), $user->id);

        return (new EntryResource($entry))->response()->setStatusCode(201);
    }
}
