<?php

namespace App\Orchestration\Setup\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Orchestration\Setup\DescribeTemplatePack;
use App\Orchestration\Setup\InstallTemplatePack;
use App\Orchestration\Setup\TemplatePacks;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @phpstan-import-type Pack from TemplatePacks
 */
class TemplatePackController
{
    /**
     * The template packs released for use. A pack is only a suggestion: nothing is created until the
     * company reviews it and confirms the install.
     *
     * @response array{data: list<array{key: string, name: string, description: string, version: int, additive: bool, components: list<array{id: string, kind: string, template: string}>}>}
     */
    public function index(TemplatePacks $packs): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn (array $pack): array => $this->summary($pack), $packs->all()))]);
    }

    /**
     * What the pack would create in this company, component by component, with the items the company already
     * has marked `exists`. Nothing is created.
     *
     * @response array{data: array{key: string, name: string, description: string, version: int, additive: bool, components: list<array{id: string, kind: string, template: string, name: string, version: int, items: list<array{key: string, label: string, exists: bool, optional: bool, conflict: string|null}>}>}}
     */
    public function show(TemplatePacks $packs, DescribeTemplatePack $describe, string $companyId, string $key): JsonResponse
    {
        $pack = $packs->find($key) ?? throw new NotFoundHttpException;

        return response()->json(['data' => [...$this->summary($pack), 'components' => $describe($pack)]]);
    }

    /**
     * Creates the picked components of the pack in one transaction: all or none. `confirmed` must be true. The
     * caller needs the permission of every picked component. Items the company already has are skipped and
     * reported, never overwritten. `exclude` lists, per component id, items to leave out.
     *
     * @status 201
     *
     * @response array{data: array{pack: string, version: int, components: list<array{id: string, template: string, created: list<string>, skipped: list<string>}>}}
     */
    public function install(
        InstallTemplatePackRequest $request,
        TemplatePacks $packs,
        InstallTemplatePack $install,
        AccessContexts $contexts,
        string $companyId,
        string $key,
    ): JsonResponse {
        $pack = $packs->find($key) ?? throw new NotFoundHttpException;
        /** @var User $user */
        $user = $request->user();
        /** @var list<string> $components */
        $components = $request->validated('components');
        /** @var array<string, list<string>> $exclude */
        $exclude = $request->validated('exclude') ?? [];

        $results = $install($pack, $components, $exclude, $contexts->current($request), $user->id);

        return response()->json(['data' => ['pack' => $pack['key'], 'version' => $pack['version'], 'components' => $results]], 201);
    }

    /**
     * @param  Pack  $pack
     * @return array{key: string, name: string, description: string, version: int, additive: bool, components: list<array{id: string, kind: string, template: string}>}
     */
    private function summary(array $pack): array
    {
        return [
            'key' => $pack['key'],
            'name' => $pack['name'],
            'description' => $pack['description'],
            'version' => $pack['version'],
            'additive' => $pack['additive'] ?? false,
            'components' => $pack['components'],
        ];
    }
}
