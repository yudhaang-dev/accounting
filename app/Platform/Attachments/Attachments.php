<?php

namespace App\Platform\Attachments;

use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Files attached to a record of a business module (X-FR-04). The subject is a type and an ID the caller
 * owns; whether it may still gain or lose attachments is a rule of the caller, this class only keeps the
 * files, their hashes and the audit trail. Files are private: they leave only through `download()`.
 */
final class Attachments
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly Audit $audit,
    ) {}

    public function store(string $subjectType, string $subjectId, UploadedFile $file, ?int $uploadedBy): Attachment
    {
        $companyId = $this->company->require();
        $path = "attachments/{$companyId}/".Str::ulid();
        $disk = Storage::disk(config('attachments.disk'));

        $disk->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($subjectType, $subjectId, $file, $uploadedBy, $path): Attachment {
                $attachment = Attachment::create([
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'filename' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'sha256' => (string) hash_file('sha256', $file->getRealPath()),
                    'storage_path' => $path,
                    'uploaded_by' => $uploadedBy,
                ])->refresh();

                $this->audit->record('attachment.added', "Attachment {$attachment->filename} added.", $attachment, after: ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'sha256' => $attachment->sha256]);

                return $attachment;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /** @return Collection<int, Attachment> attachments not removed, oldest first */
    public function forSubject(string $subjectType, string $subjectId): Collection
    {
        return Attachment::query()
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)->whereNull('removed_at')
            ->orderBy('created_at')->orderBy('id')->get();
    }

    public function find(string $subjectType, string $subjectId, string $id): Attachment
    {
        return Attachment::query()
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)->whereNull('removed_at')
            ->findOrFail($id);
    }

    /** Marks the attachment removed; the row and the file stay for the retention period (X-FR-05). */
    public function remove(Attachment $attachment, ?int $removedBy): void
    {
        DB::transaction(function () use ($attachment, $removedBy): void {
            $attachment->forceFill(['removed_at' => now(), 'removed_by' => $removedBy])->save();

            $this->audit->record('attachment.removed', "Attachment {$attachment->filename} removed.", $attachment, before: ['sha256' => $attachment->sha256]);
        });
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        return Storage::disk(config('attachments.disk'))->download($attachment->storage_path, $attachment->filename, ['Content-Type' => $attachment->mime_type]);
    }
}
