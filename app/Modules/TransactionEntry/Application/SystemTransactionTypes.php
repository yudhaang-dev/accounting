<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Modules\TransactionEntry\Domain\VersionStatus;

/**
 * The transaction types the code defines, those with a `system.` key (TE module notes). A company gets
 * one the first time an entry needs it, as a type with a single published `free_lines` version: the lines
 * of such an entry do not come from the user.
 */
final class SystemTransactionTypes
{
    public const REVERSAL = 'system.reversal';

    public function ensure(string $key, string $label): TransactionType
    {
        $type = TransactionType::query()->where('key', $key)->first();

        if ($type !== null) {
            return $type;
        }

        $type = TransactionType::create(['key' => $key, 'label' => $label]);
        TransactionTypeVersion::create(['transaction_type_id' => $type->id, 'version' => 1, 'mode' => TransactionTypeMode::FreeLines, 'status' => VersionStatus::Published]);

        return $type;
    }
}
