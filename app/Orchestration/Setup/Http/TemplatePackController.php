<?php

namespace App\Orchestration\Setup\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Orchestration\Setup\DescribeTemplatePack;
use App\Orchestration\Setup\InstallTemplatePack;
use App\Orchestration\Setup\TemplatePacks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * @response array{data: list<array{key: string, name: string, description: string, version: int, additive: bool, profiles: list<string>, components: list<array{id: string, kind: string, template: string}>}>}
     */
    public function index(TemplatePacks $packs): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn (array $pack): array => $this->summary($pack), $packs->all()))]);
    }

    /**
     * What the pack would create in this company, component by component, with the items the company already
     * has marked `exists`. Nothing is created. `profile` (a name from `profiles`, such as `simple`) narrows the
     * pack to the components of that profile.
     *
     * @response array{data: array{key: string, name: string, description: string, version: int, additive: bool, profiles: list<string>, components: list<array{id: string, kind: string, template: string, name: string, version: int, items: list<array{key: string, label: string, exists: bool, optional: bool, conflict: string|null}>}>}}
     */
    public function show(Request $request, TemplatePacks $packs, DescribeTemplatePack $describe, string $companyId, string $key): JsonResponse
    {
        $pack = $packs->find($key) ?? throw new NotFoundHttpException;
        $profile = $request->query('profile');

        $components = null;

        if (is_string($profile) && $profile !== '') {
            // With a profile only its components are described, and without the optional items the install leaves out.
            $pack['components'] = $packs->profileComponents($pack, $profile) ?? throw new NotFoundHttpException;
            $components = array_map(function (array $component) use ($pack, $profile): array {
                $keep = $pack['profiles'][$profile]['keep'][$component['id']] ?? [];
                $component['items'] = array_values(array_filter(
                    $component['items'],
                    fn (array $item): bool => $item['exists'] || $item['conflict'] !== null || ! $item['optional'] || in_array($item['key'], $keep, true),
                ));

                return $component;
            }, $describe($pack));
        }

        return response()->json(['data' => [...$this->summary($pack), 'components' => $components ?? $describe($pack)]]);
    }

    /**
     * Creates the picked components of the pack in one transaction: all or none. `confirmed` must be true. The
     * caller needs the permission of every picked component. Items the company already has are skipped and
     * reported, never overwritten. `exclude` lists, per component id, items to leave out. With `profile` (such as `simple`, for a small company
     * without an accountant) only that profile's components may be picked (all of them when `components` is omitted)
     * and the optional items are left out.
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
        $components = $request->validated('components') ?? [];
        $profile = $request->validated('profile');

        if ($profile !== null && $components === []) {
            $components = array_column($packs->profileComponents($pack, $profile) ?? [], 'id');
        }

        /** @var array<string, list<string>> $exclude */
        $exclude = $request->validated('exclude') ?? [];

        $results = $install($pack, $components, $exclude, $contexts->current($request), $user->id, $profile);

        return response()->json(['data' => ['pack' => $pack['key'], 'version' => $pack['version'], 'components' => $results]], 201);
    }

    /**
     * @param  Pack  $pack
     * @return array{key: string, name: string, description: string, version: int, additive: bool, profiles: list<string>, components: list<array{id: string, kind: string, template: string}>}
     */
    private function summary(array $pack): array
    {
        return [
            'key' => $pack['key'],
            'name' => $pack['name'],
            'description' => $pack['description'],
            'version' => $pack['version'],
            'additive' => $pack['additive'] ?? false,
            'profiles' => array_keys($pack['profiles'] ?? []),
            'components' => $pack['components'],
        ];
    }
}
