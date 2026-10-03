<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\EntryStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Attachments\Attachment;
use App\Platform\Attachments\Attachments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attachments of an entry (X-FR-04). Evidence can be added until the entry is posted and never after, so
 * what supported a posted entry stays as it was; it can be taken off only while the entry is still being
 * prepared. The files themselves are kept by Platform Attachments.
 */
final class EntryAttachments
{
    public const SUBJECT = 'entry';

    public function __construct(private readonly Attachments $attachments) {}

    /** @return Collection<int, Attachment> */
    public function list(string $entryId): Collection
    {
        Entry::query()->findOrFail($entryId);

        return $this->attachments->forSubject(self::SUBJECT, $entryId);
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION when the entry is posted or cancelled
     */
    public function add(string $entryId, UploadedFile $file, ?int $userId): Attachment
    {
        return DB::transaction(function () use ($entryId, $file, $userId): Attachment {
            $entry = Entry::query()->lockForUpdate()->findOrFail($entryId);

            $this->assertStatus($entry, [EntryStatus::Draft, EntryStatus::Submitted, EntryStatus::Approved, EntryStatus::Rejected]);

            return $this->attachments->store(self::SUBJECT, $entry->id, $file, $userId);
        });
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION when the entry is past preparation
     */
    public function remove(string $entryId, string $attachmentId, ?int $userId): void
    {
        DB::transaction(function () use ($entryId, $attachmentId, $userId): void {
            $entry = Entry::query()->lockForUpdate()->findOrFail($entryId);

            $this->assertStatus($entry, [EntryStatus::Draft, EntryStatus::Rejected]);

            $this->attachments->remove($this->attachments->find(self::SUBJECT, $entry->id, $attachmentId), $userId);
        });
    }

    public function download(string $entryId, string $attachmentId): StreamedResponse
    {
        Entry::query()->findOrFail($entryId);

        return $this->attachments->download($this->attachments->find(self::SUBJECT, $entryId, $attachmentId));
    }

    /**
     * @param  list<EntryStatus>  $allowed
     *
     * @throws ApiException
     */
    private function assertStatus(Entry $entry, array $allowed): void
    {
        if (! in_array($entry->status, $allowed, true)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['status' => [__('entries.attachments_locked', ['status' => $entry->status->value])]]);
        }
    }
}
