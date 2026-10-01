<?php

namespace App\Support\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for domain models keyed by ULID (API-TR-04).
 */
abstract class UlidModel extends Model
{
    use HasUlids;
}
