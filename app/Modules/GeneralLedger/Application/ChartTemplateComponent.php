<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Audit\Audit;
use App\Support\Template\ComponentPreview;
use App\Support\Template\PreviewItem;
use App\Support\Template\TemplateComponent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The chart of accounts as a component of a template pack (ADR-0019). A whole chart goes only into an empty
 * chart, so a company that already has accounts shows every item as existing and gets nothing. An add-on
 * template adds accounts to the chart the company has: an account whose code the company already uses with the
 * same name and class counts as existing, one with a different name or class is a conflict the caller has to
 * leave out, and nothing the company has is ever overwritten.
 *
 * @phpstan-import-type Template from ChartTemplates
 */
final class ChartTemplateComponent implements TemplateComponent
{
    public function __construct(
        private readonly ChartTemplates $templates,
        private readonly ImportAccountTemplate $import,
        private readonly Audit $audit,
    ) {}

    public function kind(): string
    {
        return 'chart_of_accounts';
    }

    public function permission(): string
    {
        return 'accounts.manage';
    }

    public function preview(string $templateKey): ComponentPreview
    {
        $template = $this->template($templateKey);

        if (! ($template['additive'] ?? false)) {
            $exists = Account::query()->exists();

            return new ComponentPreview(
                $this->kind(),
                $template['key'],
                $template['name'],
                $template['version'],
                array_map(fn (array $row): PreviewItem => new PreviewItem($row['code'], $row['name'], $exists, $row['optional']), $template['accounts']),
            );
        }

        $have = Account::query()->get()->keyBy('code');
        $items = [];

        foreach ($template['accounts'] as $row) {
            $account = $have[$row['code']] ?? null;
            $same = $account !== null && $account->name === $row['name'] && $account->class->value === $row['class'];
            $conflict = $account !== null && ! $same
                ? (string) __('accounts.template_conflict', ['code' => $row['code'], 'name' => $account->name, 'class' => $account->class->value])
                : null;

            $items[] = new PreviewItem($row['code'], $row['name'], $same, $row['optional'], $conflict);
        }

        return new ComponentPreview($this->kind(), $template['key'], $template['name'], $template['version'], $items);
    }

    public function install(string $templateKey, array $itemKeys, int $actorId): void
    {
        $template = $this->template($templateKey);

        if (! ($template['additive'] ?? false)) {
            $exclude = array_values(array_diff(array_column($template['accounts'], 'code'), $itemKeys));

            ($this->import)($templateKey, $exclude);

            return;
        }

        $this->add($template, $itemKeys);
    }

    /**
     * Creates the accounts of an add-on template that the company lacks, in template order so a parent comes
     * before its children. A parent is the account of the company with that code, or one created just now.
     *
     * @param  Template  $template
     * @param  list<string>  $itemKeys
     *
     * @throws ValidationException a parent the company does not have
     */
    private function add(array $template, array $itemKeys): void
    {
        $ids = Account::query()->pluck('id', 'code')->all();
        $created = [];

        DB::transaction(function () use ($template, $itemKeys, &$ids, &$created): void {
            foreach ($template['accounts'] as $row) {
                if (! in_array($row['code'], $itemKeys, true)) {
                    continue;
                }

                if ($row['parent'] !== null && ! isset($ids[$row['parent']])) {
                    throw ValidationException::withMessages(['accounts' => [__('accounts.template_parent_missing', ['code' => $row['code'], 'parent' => $row['parent']])]]);
                }

                $class = AccountClass::from($row['class']);
                $account = Account::create([
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'class' => $class,
                    'normal_balance' => $row['normal'] ?? $class->defaultNormalBalance(),
                    'parent_id' => $row['parent'] === null ? null : $ids[$row['parent']],
                ]);

                $ids[$row['code']] = $account->id;
                $created[] = $row['code'];
            }

            $this->audit->record(
                'accounts.template_added',
                "Accounts of chart of accounts add-on {$template['key']} added.",
                properties: ['template' => $template['key'], 'version' => $template['version'], 'created' => $created],
            );
        });
    }

    /**
     * @return Template
     *
     * @throws ValidationException
     */
    private function template(string $templateKey): array
    {
        return $this->templates->findAny($templateKey)
            ?? throw ValidationException::withMessages(['template' => [__('accounts.template_unknown')]]);
    }
}
