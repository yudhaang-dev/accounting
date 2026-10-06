<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\Reporting\Domain\ReportDefinition;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the report definitions the user picked from a template, after the user confirmed them
 * (RP-FR-01, X-AC-03). The confirmation is enforced at the HTTP boundary; this action is only reachable
 * through it. Parents named by a template code are looked up in the chart of the company, so a chart
 * that was changed after the COA import is reported instead of producing a report that misses accounts.
 * All picked definitions are created or none.
 *
 * @phpstan-import-type TemplateDefinition from ReportDefinitionTemplates
 */
final class ImportReportDefinitionTemplate
{
    public function __construct(
        private readonly ReportDefinitionTemplates $templates,
        private readonly ManageReportDefinitions $definitions,
        private readonly AccountDirectory $accounts,
        private readonly CompanyContext $company,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<string>  $keys  keys of the template definitions to create
     * @return list<string> keys of the definitions created
     *
     * @throws ValidationException unknown template or definition, a key the company already uses, or an account code the chart lacks
     */
    public function __invoke(string $templateKey, array $keys, int $actorId): array
    {
        $template = $this->templates->find($templateKey)
            ?? throw ValidationException::withMessages(['template' => [__('reports.template_unknown')]]);

        $byKey = array_column($template['definitions'], null, 'key');
        $errors = [];

        foreach ($keys as $index => $key) {
            if (! isset($byKey[$key])) {
                $errors["keys.{$index}"] = [__('reports.template_definition_unknown', ['key' => $key])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $companyId = $this->company->require();
        $idsByCode = [];

        foreach ($this->accounts->all($companyId) as $account) {
            $idsByCode[$account->code] = $account->id;
        }

        $taken = ReportDefinition::query()->whereIn('key', $keys)->pluck('key')->all();

        if ($taken !== []) {
            throw ValidationException::withMessages(['keys' => array_map(fn (string $key): string => __('reports.definition_key_taken', ['key' => $key]), $taken)]);
        }

        $structures = [];

        foreach ($keys as $index => $key) {
            $structures[$key] = $this->structure($byKey[$key], $idsByCode, "keys.{$index}", $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($template, $byKey, $keys, $structures, $actorId): array {
            foreach ($keys as $key) {
                $this->definitions->create(['key' => $key, 'name' => $byKey[$key]['name'], 'kind' => $byKey[$key]['kind'], 'structure' => $structures[$key]], $actorId);
            }

            $this->audit->record('report_definitions.template_imported', "Report definition template {$template['key']} imported.", properties: ['template' => $template['key'], 'version' => $template['version'], 'created' => $keys]);

            return $keys;
        });
    }

    /**
     * The structure with every `code` selector turned into a parent selector on the account of the company.
     *
     * @param  TemplateDefinition  $definition
     * @param  array<string, string>  $idsByCode
     * @param  array<string, list<array<mixed>|string>>  $errors
     * @return array<string, mixed>
     */
    private function structure(array $definition, array $idsByCode, string $field, array &$errors): array
    {
        $structure = $definition['structure'];

        foreach ($structure['rows'] as $r => $row) {
            foreach ($row['accounts'] ?? [] as $s => $selector) {
                if (isset($selector['class'])) {
                    $structure['rows'][$r]['accounts'][$s] = ['type' => 'class', 'class' => $selector['class']];
                } elseif (isset($idsByCode[$selector['code']])) {
                    $structure['rows'][$r]['accounts'][$s] = ['type' => 'parent', 'id' => $idsByCode[$selector['code']]];
                } else {
                    $errors[$field][] = __('reports.template_account_missing', ['code' => $selector['code'], 'key' => $definition['key']]);
                }
            }
        }

        return $structure;
    }
}
