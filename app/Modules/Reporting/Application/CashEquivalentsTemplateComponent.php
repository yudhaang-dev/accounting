<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Support\Company\CompanyContext;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;
use Illuminate\Validation\ValidationException;

/**
 * The accounts the cash flow treats as cash and cash equivalents, as a component of a template pack
 * (ADR-0019). The suggestion is the cash and bank accounts of the chart template of the same key; the ones
 * the company left out as optional are not mapped, and a chart with none of them fails the install.
 */
final class CashEquivalentsTemplateComponent implements TemplateComponent
{
    public const TEMPLATE = 'general-id';

    public const ITEM = 'cash_equivalents';

    /** @var list<string> */
    private const CODES = ['1111', '1112', '1113', '1114', '1115'];

    public function __construct(
        private readonly CashEquivalents $cashEquivalents,
        private readonly AccountDirectory $accounts,
        private readonly CompanyContext $company,
    ) {}

    public function kind(): string
    {
        return 'cash_equivalents';
    }

    public function permission(): string
    {
        return 'report-definitions.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $this->assertTemplate($templateKey);

        return new ComponentPreview(
            $this->kind(),
            self::TEMPLATE,
            (string) __('reports.cash_equivalents_template_name'),
            1,
            [new PreviewItem(self::ITEM, self::ITEM.' → '.implode(', ', self::CODES), $this->cashEquivalents->current($this->company->require()) !== null)],
        );
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        $this->assertTemplate($templateKey);

        if (! in_array(self::ITEM, $itemKeys, true)) {
            return;
        }

        $companyId = $this->company->require();
        $ids = [];

        foreach ($this->accounts->all($companyId) as $account) {
            if (in_array($account->code, self::CODES, true)) {
                $ids[] = $account->id;
            }
        }

        if ($ids === []) {
            throw ValidationException::withMessages(['cash_equivalents' => [__('reports.cash_equivalents_template_accounts_missing')]]);
        }

        $this->cashEquivalents->change($companyId, $ids, 0, $actorId);
    }

    /** @throws ValidationException */
    private function assertTemplate(string $templateKey): void
    {
        if ($templateKey !== self::TEMPLATE) {
            throw ValidationException::withMessages(['template' => [__('reports.template_unknown')]]);
        }
    }
}
