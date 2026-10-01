<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Application\ChartTemplates;
use App\Modules\GeneralLedger\Application\ImportAccountTemplate;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccountTemplateController
{
    /**
     * Chart of accounts templates the company can review. A template is only a suggestion.
     */
    public function index(ChartTemplates $templates): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn (array $template): array => [
            'key' => $template['key'],
            'name' => $template['name'],
            'version' => $template['version'],
            'account_count' => count($template['accounts']),
        ], $templates->all()))]);
    }

    /**
     * Every account of a template with its class, saldo normal, parent and whether it is optional,
     * for review before the import is confirmed.
     */
    public function show(ChartTemplates $templates, string $companyId, string $key): JsonResponse
    {
        $template = $templates->find($key) ?? throw new NotFoundHttpException;

        return response()->json(['data' => [
            'key' => $template['key'],
            'name' => $template['name'],
            'version' => $template['version'],
            'accounts' => $template['accounts'],
        ]]);
    }

    /**
     * Creates the accounts of a template in an empty chart. `confirmed` must be true: the template
     * is only a suggestion until the company confirms it. Optional leaf accounts can be left out
     * with `exclude`.
     */
    public function import(ImportTemplateRequest $request, ImportAccountTemplate $import): JsonResponse
    {
        /** @var list<string> $exclude */
        $exclude = $request->validated('exclude', []);
        $created = $import($request->string('template')->toString(), $exclude);

        return response()->json(['data' => ['template' => $request->string('template')->toString(), 'created' => $created]], 201);
    }
}
