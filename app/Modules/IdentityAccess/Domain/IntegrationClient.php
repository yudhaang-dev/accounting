<?php

namespace App\Modules\IdentityAccess\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Support\Carbon;

/**
 * A machine principal of one company (IA-FR-06). It is also what the `integration` guard returns,
 * so audit can name it as actor. It never represents a user and never approves entries.
 *
 * @property string $id
 * @property string $company_id
 * @property string $oauth_client_id
 * @property string $name
 * @property string $source_system
 * @property list<string> $scopes
 * @property list<string> $transaction_type_keys
 * @property string|null $webhook_url where the client wants its webhooks (API-TR-25)
 * @property string|null $webhook_secret signs them; encrypted at rest
 * @property IntegrationClientStatus $status
 * @property Carbon|null $secret_rotated_at
 * @property Carbon|null $revoked_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IntegrationClient extends VersionedCompanyModel implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'ia_integration_clients';

    protected $guarded = [];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'scopes' => 'array',
            'transaction_type_keys' => 'array',
            'status' => IntegrationClientStatus::class,
            'secret_rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
