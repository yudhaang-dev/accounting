<?php

namespace Database\Seeders\Demo;

/**
 * Budgets for 2025 and 2026 (PL-FR-01 to PL-FR-04): a plan per fiscal year with a published version that the
 * planning policy names as the default, which the budget report reads, and for 2026 a draft scenario still
 * being worked on. The accountant prepares the lines and the director publishes, as the policy asks.
 */
final class DemoPlanning
{
    public function __construct(private readonly DemoFoundation $f) {}

    public function run(): void
    {
        $this->f->say('Anggaran 2025 dan 2026 beserta versi default');

        $api = $this->f->api->as($this->f->users['accountant']);
        $years = collect($api->get($this->f->url('/fiscal-years'))['data'])->keyBy(fn (array $y) => (int) substr($y['start_date'], 0, 4));
        $defaults = [];

        foreach ([2025, 2026] as $year) {
            $plan = $api->post($this->f->url('/plans'), ['name' => "Anggaran {$year}", 'fiscal_year_id' => $years[$year]['id'], 'description' => "Anggaran tahunan {$year} yang disetujui direksi"])['data'];
            $version = $api->post($this->f->url("/plans/{$plan['id']}/versions"), ['name' => 'Anggaran awal'])['data'];
            $periods = collect($api->get($this->f->url('/periods'), ['filter[fiscal_year_id]' => $years[$year]['id'], 'per_page' => 50])['data'])->sortBy('number')->values();

            $api->put($this->f->url("/plan-versions/{$version['id']}/lines"), ['lines' => $this->lines($year, $periods->pluck('id')->all(), 1.0)]);

            $version = $api->get($this->f->url("/plan-versions/{$version['id']}"))['data'];
            $this->f->api->as($this->f->users['owner'])->post($this->f->url("/plan-versions/{$version['id']}/publish"), ['lock_version' => $version['lock_version']]);
            $api->as($this->f->users['accountant']);

            $defaults[] = ['fiscal_year_id' => $years[$year]['id'], 'plan_version_id' => $version['id']];

            if ($year === 2026) {
                $scenario = $api->post($this->f->url("/plans/{$plan['id']}/versions"), ['name' => 'Skenario pertumbuhan tinggi (draf)'])['data'];
                $api->put($this->f->url("/plan-versions/{$scenario['id']}/lines"), ['lines' => $this->lines($year, $periods->pluck('id')->all(), 1.12)]);
            }
        }

        $this->f->api->as($this->f->users['owner'])->put($this->f->url('/planning-policy'), [
            'lock_version' => 0,
            'require_distinct_publisher' => true,
            'default_versions' => $defaults,
        ]);
    }

    /**
     * One line per account and month, from the same growth and season the trading follows, a little more
     * hopeful than what happens.
     *
     * @param  list<string>  $periodIds
     * @return list<array<string, string>>
     */
    private function lines(int $year, array $periodIds, float $stretch): array
    {
        $lines = [];

        foreach ($periodIds as $i => $period) {
            $month = $i + 1;
            $index = ($year - 2025) * 12 + $i;
            $season = match ($month) {
                1, 2 => 0.93, 11, 12 => 1.16, 6, 7 => 0.97, default => 1.0,
            };
            $goods = (int) (round(665_000_000 * (1 + 0.017 * $index) * $season * $stretch / 1_000_000) * 1_000_000);
            $services = (int) (round(70_000_000 * (1 + 0.017 * $index) * $season * $stretch / 1_000_000) * 1_000_000);

            $amounts = [
                '4110' => $goods,
                '4120' => $services,
                '5110' => (int) (round($goods * 0.66 / 1_000_000) * 1_000_000),
                '6110' => $year >= 2026 ? 23_000_000 : 18_000_000,
                '6130' => (int) (round(($goods + $services) * 0.011 / 1_000_000) * 1_000_000),
                '6210' => $year >= 2026 ? 140_000_000 : 120_000_000,
                '6220' => 3_000_000,
                '6230' => 18_000_000,
                '6240' => 2_500_000,
                '6260' => in_array($month, [3, 6, 9, 12], true) ? 18_000_000 : 0,
                '6270' => 4_800_000,
                '6280' => 175_000,
                '6310' => 12_500_000,
                '7210' => 2_500_000,
            ];

            foreach ($amounts as $code => $amount) {
                if ($amount > 0) {
                    $lines[] = ['period_id' => $period, 'account_id' => $this->f->account((string) $code), 'amount' => (string) $amount];
                }
            }
        }

        return $lines;
    }
}
