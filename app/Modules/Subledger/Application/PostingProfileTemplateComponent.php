<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Domain\PostingProfile;
use App\Support\Company\CompanyContext;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;
use Illuminate\Validation\ValidationException;

/**
 * The default posting profile with its receivables and payables control accounts, as a component of a
 * template pack (ADR-0019). A profile belongs to the company, so the template names accounts by the codes of
 * the chart template of the same key, and the profile is created and mapped here, after the chart. A code the
 * chart lacks fails the install instead of mapping a guess.
 */
final class PostingProfileTemplateComponent implements TemplateComponent
{
    public const TEMPLATE = 'general-id';

    public const PROFILE = 'default';

    /** @var array<string, string> control role key => account code of the chart template */
    private const MAPPING = [
        'receivables_control' => '1121',
        'payables_control' => '2110',
    ];

    public function __construct(
        private readonly ManagePostingProfiles $profiles,
        private readonly AccountDirectory $accounts,
        private readonly CompanyContext $company,
    ) {}

    public function kind(): string
    {
        return 'posting_profiles';
    }

    public function permission(): string
    {
        return 'posting-profiles.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $this->assertTemplate($templateKey);
        $name = $this->name();

        return new ComponentPreview(
            $this->kind(),
            self::TEMPLATE,
            (string) __('posting_profiles.template_name'),
            1,
            [new PreviewItem(self::PROFILE, $name, PostingProfile::query()->where('name', $name)->exists())],
        );
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        $this->assertTemplate($templateKey);

        if (! in_array(self::PROFILE, $itemKeys, true)) {
            return;
        }

        /** @var array<int|string, string> $idsByCode account IDs by code; PHP keeps a numeric code as an integer key */
        $idsByCode = [];

        foreach ($this->accounts->all($this->company->require()) as $account) {
            $idsByCode[$account->code] = $account->id;
        }

        $errors = [];

        foreach (self::MAPPING as $role => $code) {
            if (! isset($idsByCode[$code])) {
                $errors["profile.{$role}"] = [__('posting_profiles.template_account_missing', ['account_role' => $role, 'code' => $code])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $profile = $this->profiles->create(['name' => $this->name()]);

        foreach (self::MAPPING as $role => $code) {
            $this->profiles->mapAccount($profile->id, ControlRole::from($role), $idsByCode[$code], 0, $actorId);
        }
    }

    private function name(): string
    {
        return (string) __('posting_profiles.template_profile_name');
    }

    /** @throws ValidationException */
    private function assertTemplate(string $templateKey): void
    {
        if ($templateKey !== self::TEMPLATE) {
            throw ValidationException::withMessages(['template' => [__('accounts.template_unknown')]]);
        }
    }
}
