<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the transaction types the user picked from a template, each with a published first version,
 * after the user confirmed them (TE-FR-01, X-AC-03). The confirmation is enforced at the HTTP boundary;
 * this action is only reachable through it. All picked types are created or none.
 */
final class ImportTransactionTypeTemplate
{
    public function __construct(
        private readonly TransactionTypeTemplates $templates,
        private readonly TransactionTypeVersions $versions,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<string>  $keys  keys of the template types to create
     * @return list<string> keys of the types created
     *
     * @throws ValidationException unknown template or type, or a key the company already uses
     */
    public function __invoke(string $templateKey, array $keys): array
    {
        $template = $this->templates->find($templateKey)
            ?? throw ValidationException::withMessages(['template' => [__('entries.template_unknown')]]);

        $byKey = array_column($template['types'], null, 'key');
        $errors = [];

        foreach ($keys as $index => $key) {
            if (! isset($byKey[$key])) {
                $errors["keys.{$index}"] = [__('entries.template_type_unknown', ['key' => $key])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($template, $byKey, $keys): array {
            $taken = TransactionType::query()->whereIn('key', $keys)->pluck('key')->all();

            if ($taken !== []) {
                throw ValidationException::withMessages(['keys' => array_map(fn (string $key): string => __('entries.type_key_taken', ['key' => $key]), $taken)]);
            }

            foreach ($keys as $key) {
                $row = $byKey[$key];
                $type = TransactionType::create(['key' => $row['key'], 'label' => $row['label'], 'description' => $row['description'] ?? null])->refresh();

                $this->versions->createDraft($type->id, TransactionTypeMode::from($row['mode']), $row['fields'], $row['posting_rules'], route: $row['subledger_route'] ?? null);
                $this->versions->publish($type->id, 1);

                $this->audit->record('transaction_type.created', "Transaction type {$type->key} created from template {$template['key']}.", $type, after: ['key' => $type->key, 'label' => $type->label, 'description' => $type->description, 'version' => 1], companyId: $type->company_id);
            }

            $this->audit->record('transaction_types.template_imported', "Transaction type template {$template['key']} imported.", properties: ['template' => $template['key'], 'version' => $template['version'], 'created' => $keys]);

            return $keys;
        });
    }
}
