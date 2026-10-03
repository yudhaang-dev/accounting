<?php

namespace App\Modules\TransactionEntry\Http;

use App\Platform\Attachments\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Attachment $resource
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array{id: string, filename: string, mime_type: string, size_bytes: int, sha256: string, uploaded_by: int|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'filename' => $this->resource->filename,
            'mime_type' => $this->resource->mime_type,
            'size_bytes' => $this->resource->size_bytes,
            'sha256' => $this->resource->sha256,
            'uploaded_by' => $this->resource->uploaded_by,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
