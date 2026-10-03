<?php

namespace App\Modules\TransactionEntry\Http;

use App\Models\User;
use App\Modules\TransactionEntry\Application\EntryAttachments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to an entry (X-FR-04): list, add, download and remove.
 */
class EntryAttachmentController
{
    public function index(string $companyId, string $id, EntryAttachments $attachments): AnonymousResourceCollection
    {
        return AttachmentResource::collection($attachments->list($id));
    }

    /** Adds a file (multipart field `file`) to an entry that is not posted or cancelled. */
    public function store(AttachmentRequest $request, string $companyId, string $id, EntryAttachments $attachments): JsonResponse
    {
        $attachment = $attachments->add($id, $request->file('file'), $this->userId($request));

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(string $companyId, string $id, string $attachmentId, EntryAttachments $attachments): StreamedResponse
    {
        return $attachments->download($id, $attachmentId);
    }

    /** Takes a file off an entry that is still a draft or rejected; the record is kept. */
    public function destroy(Request $request, string $companyId, string $id, string $attachmentId, EntryAttachments $attachments): Response
    {
        $attachments->remove($id, $attachmentId, $this->userId($request));

        return response()->noContent();
    }

    private function userId(Request $request): ?int
    {
        $user = $request->user();

        return $user instanceof User ? $user->id : null;
    }
}
