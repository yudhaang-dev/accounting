<?php

namespace App\Modules\IdentityAccess\Application;

use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Reads and changes the security policy of a company (IA-FR-05). Takes the company explicitly: the
 * access gate reads it before the company context exists. Every change is a new version with its
 * author and is audited, so earlier versions stay as they were (P-04).
 */
final class SecurityPolicies
{
    public function __construct(private readonly Audit $audit) {}

    public function current(string $companyId): SecurityPolicyData
    {
        $row = DB::table('ia_security_policies')->where('company_id', $companyId)->orderByDesc('version')->first();

        return $row === null ? SecurityPolicyData::none() : $this->data($row);
    }

    /**
     * @param  list<string>  $twoFactorPermissions
     * @param  list<string>  $clientIpAllowlist
     * @param  int  $expectedVersion  version the editor saw; 0 when the company has none yet
     *
     * @throws ApiException VERSION_CONFLICT
     */
    public function change(string $companyId, array $twoFactorPermissions, ?int $idleTimeoutMinutes, array $clientIpAllowlist, int $expectedVersion, int $actorId): SecurityPolicyData
    {
        try {
            return DB::transaction(function () use ($companyId, $twoFactorPermissions, $idleTimeoutMinutes, $clientIpAllowlist, $expectedVersion, $actorId): SecurityPolicyData {
                $before = $this->current($companyId);

                if ($before->version !== $expectedVersion) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }

                $permissions = array_values(array_unique($twoFactorPermissions));
                sort($permissions);

                DB::table('ia_security_policies')->insert([
                    'id' => (string) Str::ulid(),
                    'company_id' => $companyId,
                    'version' => $before->version + 1,
                    'two_factor_permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                    'idle_timeout_minutes' => $idleTimeoutMinutes,
                    'client_ip_allowlist' => json_encode(array_values(array_unique($clientIpAllowlist)), JSON_THROW_ON_ERROR),
                    'created_by' => $actorId,
                    'created_at' => now(),
                ]);

                $after = $this->current($companyId);

                $this->audit->record(
                    'security_policy.changed',
                    "Security policy changed to version {$after->version}.",
                    before: $this->snapshot($before),
                    after: $this->snapshot($after),
                    companyId: $companyId,
                );

                return $after;
            });
        } catch (UniqueConstraintViolationException) {
            // Another editor added the same version first.
            throw new ApiException(ErrorCode::VersionConflict);
        }
    }

    /** @return array{version: int, two_factor_permissions: list<string>, idle_timeout_minutes: int|null, client_ip_allowlist: list<string>} */
    private function snapshot(SecurityPolicyData $policy): array
    {
        return [
            'version' => $policy->version,
            'two_factor_permissions' => $policy->twoFactorPermissions,
            'idle_timeout_minutes' => $policy->idleTimeoutMinutes,
            'client_ip_allowlist' => $policy->clientIpAllowlist,
        ];
    }

    private function data(stdClass $row): SecurityPolicyData
    {
        /** @var list<string> $permissions */
        $permissions = json_decode((string) $row->two_factor_permissions, true, flags: JSON_THROW_ON_ERROR);
        /** @var list<string> $allowlist */
        $allowlist = json_decode((string) $row->client_ip_allowlist, true, flags: JSON_THROW_ON_ERROR);

        return new SecurityPolicyData(
            (int) $row->version,
            $permissions,
            $row->idle_timeout_minutes === null ? null : (int) $row->idle_timeout_minutes,
            $allowlist,
        );
    }
}
