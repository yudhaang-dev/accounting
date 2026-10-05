<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * The reversal of a document (SL-FR-12): the journal that undid its posting, and when and by whom.
 *
 * @property string $id
 * @property string $company_id
 * @property string $document_id
 * @property string $journal_id the journal of the reversal
 * @property Carbon $reversal_date
 * @property int|null $reversed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentReversal extends CompanyScopedModel
{
    protected $table = 'sl_document_reversals';

    protected $fillable = ['document_id', 'journal_id', 'reversal_date', 'reversed_by'];

    protected function casts(): array
    {
        return ['reversal_date' => 'date'];
    }
}
