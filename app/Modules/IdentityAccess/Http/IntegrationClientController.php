<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Application\IntegrationClientDataScopes;
use App\Modules\IdentityAccess\Application\ManageIntegrationClients;
use App\Modules\IdentityAccess\Domain\IntegrationClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IntegrationClientController
{
    /**
     * Integration clients of the company, newest first. Filter: `filter[status]` is `active` or
     * `revoked`. `per_page` defaults to 50, at most 200. Secrets are never listed.
     */
    public function index(ListIntegrationClientsRequest $request, IntegrationClientDataScopes $dataScopes): AnonymousResourceCollection
    {
        $status = $request->validated('filter.status');

        $page = IntegrationClient::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        $dataScopes->attach($page->items());

        return IntegrationClientResource::collection($page);
    }

    public function show(IntegrationClientDataScopes $dataScopes, string $companyId, string $id): IntegrationClientResource
    {
        return new IntegrationClientResource($this->withScope($dataScopes, IntegrationClient::query()->findOrFail($id)));
    }

    /**
     * Registers a client for one `source_system`, optionally limited to dimension values with `data_scope`.
     * The response carries `client_id` and
     * `client_secret`; the secret is shown this once and only its hash is kept. The client then
     * requests tokens at `POST /api/v1/oauth/token` with `grant_type=client_credentials` and HTTP
     * Basic authentication (`client_secret_basic`).
     */
    public function store(CreateIntegrationClientRequest $request, ManageIntegrationClients $clients, IntegrationClientDataScopes $dataScopes): JsonResponse
    {
        /** @var array{name: string, source_system: string, scopes: list<string>, transaction_type_keys?: list<string>, data_scope?: list<string>} $data */
        $data = $request->validated();

        $registered = $clients->register($data['name'], $data['source_system'], $data['scopes'], $data['transaction_type_keys'] ?? [], $this->userId($request), $data['data_scope'] ?? []);

        return (new IntegrationClientWithSecretResource($this->withScope($dataScopes, $registered['client']), $registered['secret']))->response()->setStatusCode(201);
    }

    /**
     * Renames the client or replaces its scopes, transaction type allowlist or data scope. `lock_version` is required.
     */
    public function update(UpdateIntegrationClientRequest $request, ManageIntegrationClients $clients, IntegrationClientDataScopes $dataScopes, string $companyId, string $id): IntegrationClientResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new IntegrationClientResource($this->withScope($dataScopes, $clients->update($id, $data, $expected)));
    }

    /**
     * Replaces the secret and revokes all active tokens at once. The new secret is shown this once.
     */
    public function rotateSecret(ManageIntegrationClients $clients, IntegrationClientDataScopes $dataScopes, string $companyId, string $id): IntegrationClientWithSecretResource
    {
        $rotated = $clients->rotateSecret($id);

        return new IntegrationClientWithSecretResource($this->withScope($dataScopes, $rotated['client']), $rotated['secret']);
    }

    /**
     * Revokes the client for good: its tokens stop at once and no new ones are issued.
     */
    public function revoke(ManageIntegrationClients $clients, IntegrationClientDataScopes $dataScopes, string $companyId, string $id): IntegrationClientResource
    {
        return new IntegrationClientResource($this->withScope($dataScopes, $clients->revoke($id)));
    }

    private function withScope(IntegrationClientDataScopes $dataScopes, IntegrationClient $client): IntegrationClient
    {
        $dataScopes->attach([$client]);

        return $client;
    }

    private function userId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->id;
    }
}
