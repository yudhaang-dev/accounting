<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\IntegrationClient;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Platform\Api\ApiException;
use App\Support\Access\DataScope;
use Illuminate\Support\Facades\DB;

/**
 * Reads and replaces the dimension values an integration client is limited to (IA-FR-03, IA-FR-06).
 * As with a membership, the stored values are the ones an admin picked and the descendants are added
 * when the scope is built, so a value added below one of them later falls inside it without a change here.
 */
final class IntegrationClientDataScopes
{
    public function __construct(private readonly DimensionSets $dimensionSets) {}

    /**
     * The scope of the client in its company; unrestricted when none was set.
     */
    public function forClient(string $clientId, string $companyId): DataScope
    {
        $valueIds = DB::table('ia_integration_client_data_scopes')
            ->where('integration_client_id', $clientId)
            ->where('company_id', $companyId)
            ->pluck('dimension_value_id')
            ->all();

        return $valueIds === []
            ? DataScope::unrestricted()
            : DataScope::restrictedTo($this->dimensionSets->expandValues($companyId, array_values(array_map('strval', $valueIds))));
    }

    /**
     * Value IDs picked for each client, sorted.
     *
     * @param  list<string>  $clientIds
     * @return array<string, list<string>>
     */
    public function valueIds(array $clientIds): array
    {
        $byClient = array_fill_keys($clientIds, []);

        $rows = DB::table('ia_integration_client_data_scopes')
            ->whereIn('integration_client_id', $clientIds)
            ->orderBy('dimension_value_id')
            ->get(['integration_client_id', 'dimension_value_id']);

        foreach ($rows as $row) {
            $byClient[$row->integration_client_id][] = $row->dimension_value_id;
        }

        return $byClient;
    }

    /**
     * Sets the `data_scope` relation of every client to its list of value IDs.
     *
     * @param  iterable<IntegrationClient>  $clients
     */
    public function attach(iterable $clients): void
    {
        $clients = collect($clients);
        $byClient = $this->valueIds(array_values(array_map(fn (IntegrationClient $client): string => $client->id, $clients->all())));

        foreach ($clients as $client) {
            $client->setRelation('data_scope', collect($byClient[$client->id]));
        }
    }

    /**
     * Replaces the scope; an empty list removes the restriction.
     *
     * @param  list<string>  $valueIds
     *
     * @throws ApiException DIMENSION_INVALID for a value of another company or one that does not exist
     */
    public function replace(string $clientId, string $companyId, array $valueIds): void
    {
        $valueIds = array_values(array_unique($valueIds));

        if ($valueIds !== []) {
            $this->dimensionSets->expandValues($companyId, $valueIds);
        }

        DB::table('ia_integration_client_data_scopes')->where('integration_client_id', $clientId)->delete();

        DB::table('ia_integration_client_data_scopes')->insert(array_map(fn (string $valueId): array => [
            'integration_client_id' => $clientId,
            'company_id' => $companyId,
            'dimension_value_id' => $valueId,
            'created_at' => now(),
        ], $valueIds));
    }
}
