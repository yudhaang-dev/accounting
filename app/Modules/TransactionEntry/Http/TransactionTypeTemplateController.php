<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Application\ImportTransactionTypeTemplate;
use App\Modules\TransactionEntry\Application\TransactionTypeTemplates;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TransactionTypeTemplateController
{
    /**
     * Transaction type templates the company can review. A template is only a suggestion.
     *
     * @response array{data: list<array{key: string, name: string, version: int, type_count: int}>}
     */
    public function index(TransactionTypeTemplates $templates): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn (array $template): array => [
            'key' => $template['key'],
            'name' => $template['name'],
            'version' => $template['version'],
            'type_count' => count($template['types']),
        ], $templates->all()))]);
    }

    /**
     * Every type of a template with its mode, fields and posting rules, for review before the import is confirmed.
     *
     * @response array{data: array{key: string, name: string, version: int, types: list<array{key: string, label: string, mode: string, fields: list<array<string, mixed>>, posting_rules: list<array<string, mixed>>}>}}
     */
    public function show(TransactionTypeTemplates $templates, string $companyId, string $key): JsonResponse
    {
        $template = $templates->find($key) ?? throw new NotFoundHttpException;

        return response()->json(['data' => [
            'key' => $template['key'],
            'name' => $template['name'],
            'version' => $template['version'],
            'types' => $template['types'],
        ]]);
    }

    /**
     * Creates the picked types of a template, each with a published first version. `confirmed` must be
     * true: the template is only a suggestion until the company confirms it. Fails as a whole when a key
     * is already used in the company.
     */
    public function import(ImportTypeTemplateRequest $request, ImportTransactionTypeTemplate $import): JsonResponse
    {
        /** @var list<string> $keys */
        $keys = $request->validated('keys');
        $created = $import($request->string('template')->toString(), $keys);

        return response()->json(['data' => ['template' => $request->string('template')->toString(), 'created' => $created]], 201);
    }
}
