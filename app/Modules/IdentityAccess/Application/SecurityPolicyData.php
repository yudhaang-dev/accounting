<?php

namespace App\Modules\IdentityAccess\Application;

/**
 * One version of the security policy of a company (IA-FR-05). Version 0 stands for a company that
 * never set one: nothing is required.
 */
final readonly class SecurityPolicyData
{
    /**
     * @param  list<string>  $twoFactorPermissions  permissions that need 2FA on the user's account
     * @param  list<string>  $clientIpAllowlist  IPs or CIDR ranges integration clients may call from; empty means any
     */
    public function __construct(
        public int $version,
        public array $twoFactorPermissions,
        public ?int $idleTimeoutMinutes,
        public array $clientIpAllowlist,
    ) {}

    public static function none(): self
    {
        return new self(0, [], null, []);
    }
}
