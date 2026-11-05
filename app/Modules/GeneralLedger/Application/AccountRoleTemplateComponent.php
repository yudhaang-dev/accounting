<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Support\Company\CompanyContext;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;
use Illuminate\Validation\ValidationException;

/**
 * The accounts that play the ledger roles, as a component of a template pack (ADR-0019). The suggestions
 * are the codes of CHART-OF-ACCOUNTS.md, so they belong to the chart template of the same key; a role that
 * is already mapped is left alone, and a code the chart lacks fails the install instead of mapping a guess.
 */
final class AccountRoleTemplateComponent implements TemplateComponent
{
    public const TEMPLATE = 'general-id';

    /** @var array<string, string> role key => account code of the chart template */
    private const SUGGESTIONS = [
        'retained_earnings' => '3130',
        'fx_realized_gain' => '7130',
        'fx_realized_loss' => '7230',
        'fx_unrealized_gain' => '7130',
        'fx_unrealized_loss' => '7230',
        'fx_rounding' => '7230',
    ];

    public function __construct(
        private readonly AccountRoles $roles,
        private readonly AccountDirectory $accounts,
        private readonly CompanyContext $company,
    ) {}

    public function kind(): string
    {
        return 'account_roles';
    }

    public function permission(): string
    {
        return 'accounts.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $this->assertTemplate($templateKey);
        $mapped = $this->roles->all($this->company->require());

        return new ComponentPreview(
            $this->kind(),
            self::TEMPLATE,
            (string) __('accounts.roles_template_name'),
            1,
            array_map(fn (string $role, string $code): PreviewItem => new PreviewItem($role, "{$role} → {$code}", isset($mapped[$role])), array_keys(self::SUGGESTIONS), self::SUGGESTIONS),
        );
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        $this->assertTemplate($templateKey);
        $companyId = $this->company->require();
        /** @var array<int|string, string> $idsByCode account IDs by code; PHP keeps a numeric code as an integer key */
        $idsByCode = [];

        foreach ($this->accounts->all($companyId) as $account) {
            $idsByCode[$account->code] = $account->id;
        }

        $errors = [];

        foreach ($itemKeys as $role) {
            $code = self::SUGGESTIONS[$role] ?? null;

            if ($code === null || ! isset($idsByCode[$code])) {
                $errors["roles.{$role}"] = [__('accounts.role_account_missing', ['account_role' => $role, 'code' => (string) $code])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($itemKeys as $role) {
            $this->roles->change($companyId, AccountRole::from($role), $idsByCode[self::SUGGESTIONS[$role]], 0, $actorId);
        }
    }

    /** @throws ValidationException */
    private function assertTemplate(string $templateKey): void
    {
        if ($templateKey !== self::TEMPLATE) {
            throw ValidationException::withMessages(['template' => [__('accounts.template_unknown')]]);
        }
    }
}
