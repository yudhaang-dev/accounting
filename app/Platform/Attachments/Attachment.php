<?php

namespace App\Platform\Attachments;

use App\Platform\Retention\RetainedCompanyModel;
use Illuminate\Support\Carbon;

/**
 * Metadata of one attached file (X-FR-04); the content hash lets anyone check the file was not altered.
 *
 * @property string $id
 * @property string $company_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $storage_path
 * @property int|null $uploaded_by
 * @property Carbon|null $removed_at
 * @property int|null $removed_by
 * @property Carbon|null $created_at
 */
class Attachment extends RetainedCompanyModel
{
    protected $table = 'pf_attachments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'removed_at' => 'datetime'];
    }
}
