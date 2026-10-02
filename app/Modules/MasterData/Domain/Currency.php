<?php

namespace App\Modules\MasterData\Domain;

use Illuminate\Database\Eloquent\Model;

/**
 * An ISO 4217 currency of the shared reference table (MD-FR-06). Read only, not company scoped.
 *
 * @property string $code
 * @property string $name
 * @property int $minor_unit
 */
class Currency extends Model
{
    protected $table = 'md_currencies';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
