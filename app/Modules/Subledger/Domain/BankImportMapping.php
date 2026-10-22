<?php

namespace App\Modules\Subledger\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Support\Carbon;

/**
 * Where the columns of a bank's CSV are for one cash account (SL-FR-13). Columns are positions counted
 * from 1. The amount is either one signed column (positive is money in) or a column for money in and
 * one for money out.
 *
 * @property string $id
 * @property string $company_id
 * @property string $cash_account_id
 * @property string $delimiter
 * @property string $date_format a PHP date format such as `Y-m-d` or `d/m/Y`
 * @property string $decimal_separator
 * @property bool $has_header
 * @property int $date_column
 * @property int|null $description_column
 * @property int|null $reference_column
 * @property int|null $amount_column
 * @property int|null $money_in_column
 * @property int|null $money_out_column
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BankImportMapping extends VersionedCompanyModel
{
    /** Date layouts a bank export can use. */
    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd.m.Y', 'Ymd'];

    public const FIELDS = ['delimiter', 'date_format', 'decimal_separator', 'has_header', 'date_column', 'description_column', 'reference_column', 'amount_column', 'money_in_column', 'money_out_column'];

    protected $table = 'sl_bank_import_mappings';

    protected $fillable = ['cash_account_id', ...self::FIELDS];

    protected function casts(): array
    {
        return ['has_header' => 'boolean'];
    }
}
