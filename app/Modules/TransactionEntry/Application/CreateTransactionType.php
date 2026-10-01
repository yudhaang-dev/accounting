<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Modules\TransactionEntry\Domain\VersionStatus;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Defines a transaction type with its first published version (TE-FR-01, TE-FR-02). Until the
 * structured mode arrives (M5-01) every type is a `free_lines` type.
 */
final class CreateTransactionType
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws ValidationException when the key is already used in the company
     */
    public function __invoke(string $key, string $label): TransactionType
    {
        return DB::transaction(function () use ($key, $label): TransactionType {
            if (TransactionType::query()->where('key', $key)->exists()) {
                throw ValidationException::withMessages(['key' => [__('entries.type_key_taken', ['key' => $key])]]);
            }

            $type = TransactionType::create(['key' => $key, 'label' => $label])->refresh();

            TransactionTypeVersion::create([
                'transaction_type_id' => $type->id,
                'version' => 1,
                'mode' => TransactionTypeMode::FreeLines,
                'status' => VersionStatus::Published,
            ]);

            $this->audit->record(
                'transaction_type.created',
                "Transaction type {$type->key} created.",
                $type,
                after: ['key' => $type->key, 'label' => $type->label, 'version' => 1],
                companyId: $type->company_id,
            );

            return $type->load('versions');
        });
    }
}
