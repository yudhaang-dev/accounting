<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\ImportReportDefinitionTemplate;
use App\Modules\Reporting\Application\ReportDefinitionTemplates;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReportDefinitionTemplateController
{
    /**
     * Report definition templates the company can review. A template is only a suggestion.
     *
     * @response array{data: list<array{key: string, name: string, version: int, definition_count: int}>}
     */
    public function index(ReportDefinitionTemplates $templates): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn (array $template): array => [
            'key' => $template['key'],
            'name' => $template['name'],
            'version' => $template['version'],
            'definition_count' => count($template['definitions']),
        ], $templates->all()))]);
    }

    /**
     * Every definition of a template with its kind and structure, for review before the import is
     * confirmed. Account selectors name a parent by its template `code` or a class; the import
     * resolves the code against the chart of the company.
     *
     * @response array{data: array{key: string, name: string, version: int, definitions: list<array{key: string, name: string, kind: string, structure: array<string, mixed>}>}}
     */
    public function show(ReportDefinitionTemplates $templates, string $companyId, string $key): JsonResponse
    {
        $template = $templates->find($key) ?? throw new NotFoundHttpException;

        return response()->json(['data' => [
            'key' => $template['key'],
            'name' => $template['name'],
            'version' => $template['version'],
            'definitions' => $template['definitions'],
        ]]);
    }

    /**
     * Creates the picked definitions of a template as version 1. `confirmed` must be true: the
     * template is only a suggestion until the company confirms it. Fails as a whole when a key is
     * already used in the company or the chart lacks an account the template names.
     */
    public function import(ImportReportTemplateRequest $request, ImportReportDefinitionTemplate $import): JsonResponse
    {
        /** @var list<string> $keys */
        $keys = $request->validated('keys');
        $template = $request->string('template')->toString();
        $created = $import($template, $keys, (int) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['template' => $template, 'created' => $created]], 201);
    }
}
