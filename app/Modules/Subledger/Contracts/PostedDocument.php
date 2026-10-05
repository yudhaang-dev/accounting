<?php

namespace App\Modules\Subledger\Contracts;

use App\Modules\GeneralLedger\Contracts\PostedJournal;

final readonly class PostedDocument
{
    /** @param  PostedJournal  $journal  `replayed` tells that the document existed already */
    public function __construct(
        public string $id,
        public PostedJournal $journal,
    ) {}
}
