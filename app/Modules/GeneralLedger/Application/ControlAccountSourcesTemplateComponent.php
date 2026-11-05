<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;
use Illuminate\Validation\ValidationException;

/**
 * Restricts the control accounts of the chart template to postings from the subledger, as a component of a
 * template pack (ADR-0019). A posting profile and a cash account accept only such accounts (SL-FR-02,
 * SL-FR-03), and the chart template does not impose it, because a company that keeps its books by hand
 * posts to them as entries. An account the company left out as optional is skipped.
 */
final class ControlAccountSourcesTemplateComponent implements TemplateComponent
{
    public const TEMPLATE = 'general-id';

    /** @var list<string> cash, bank, receivable and payable control accounts of the chart template */
    private const CODES = ['1111', '1112', '1113', '1115', '1121', '1123', '2110'];

    public function __construct(private readonly UpdateAccount $update) {}

    public function kind(): string
    {
        return 'account_posting';
    }

    public function permission(): string
    {
        return 'accounts.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $this->assertTemplate($templateKey);
        $restricted = Account::query()->whereIn('code', self::CODES)->get()->filter(fn (Account $account): bool => $account->allowed_sources === ['subledger'])->pluck('code')->all();

        return new ComponentPreview(
            $this->kind(),
            self::TEMPLATE,
            (string) __('accounts.posting_template_name'),
            1,
            array_map(fn (string $code): PreviewItem => new PreviewItem($code, (string) __('accounts.posting_subledger_only', ['code' => $code]), in_array($code, $restricted, true)), self::CODES),
        );
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        $this->assertTemplate($templateKey);

        foreach (Account::query()->whereIn('code', array_intersect(self::CODES, $itemKeys))->get() as $account) {
            ($this->update)($account->id, ['allowed_sources' => ['subledger']], $account->lock_version);
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
