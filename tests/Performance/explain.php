<?php

/*
| Query analysis of D3e: calls the read endpoints in-process as the owner of the first perf company, records every
| query, and reports per endpoint the wall time, the number of queries, repeated query shapes (N+1), and the slowest
| SELECTs with EXPLAIN (ANALYZE, BUFFERS). Needs the manifest of the volume seeder.
|
|   php tests/Performance/explain.php [--slow=20] [--top=3] [--plans]
|
| `--slow` is the milliseconds above which a query counts as slow; `--plans` prints the EXPLAIN output (long).
*/

use App\Models\User;
use Database\Seeders\Demo\DemoApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $options[$key] = $value;
    }
}

$slow = (float) ($options['slow'] ?? 20);
$top = (int) ($options['top'] ?? 3);
$manifest = json_decode((string) file_get_contents(storage_path('app/perf-manifest.json')), true);
$c = $manifest['companies'][0];
$api = new DemoApi;
$api->as(User::query()->where('email', $c['email'])->firstOrFail());

$base = "/api/v1/companies/{$c['company_id']}";
$periods = $c['periods'];
$first = $periods[0]['start'];
$last = $periods[count($periods) - 1]['end'];
$yearEnd = $periods[min(11, count($periods) - 1)]['end'];
$definitions = collect($api->get("{$base}/report-definitions")['data'])->pluck('id', 'key');
$branch = $c['dimension_values']['branch'][0];

$calls = [
    'journals' => "{$base}/journals?per_page=50",
    'journals by period' => "{$base}/journals?per_page=50&filter[from]={$periods[1]['start']}&filter[to]={$periods[1]['end']}",
    'journals by account' => "{$base}/journals?per_page=50&filter[account_id]={$c['accounts']['cash']}",
    'journals by dimension' => "{$base}/journals?per_page=50&filter[dimensions][branch]={$branch}",
    'open items' => "{$base}/open-items?per_page=50&filter[status]=open",
    'trial balance, one year' => "{$base}/reports/trial-balance?from={$first}&to={$yearEnd}",
    'trial balance, all years' => "{$base}/reports/trial-balance?from={$first}&to={$last}",
    'trial balance by dimension' => "{$base}/reports/trial-balance?from={$first}&to={$last}&dimensions[branch]={$branch}",
    'general ledger, cash' => "{$base}/reports/general-ledger?account_id={$c['accounts']['cash']}&from={$first}&to={$yearEnd}",
    'aging' => "{$base}/reports/aging?as_of={$yearEnd}&control_role=receivables_control",
];

foreach (['income_statement', 'balance_sheet', 'cash_flow_indirect'] as $key) {
    if (isset($definitions[$key])) {
        $range = $key === 'balance_sheet' ? "from={$yearEnd}&to={$yearEnd}" : "from={$first}&to={$yearEnd}";
        $calls[$key] = "{$base}/report-definitions/{$definitions[$key]}/result?{$range}";
    }
}

$log = [];
DB::listen(function ($query) use (&$log): void {
    $log[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time];
});

foreach ($calls as $label => $uri) {
    // Once to warm the plan and cache caches, once to measure.
    $api->send('GET', $uri);
    $log = [];
    $started = microtime(true);
    $api->send('GET', $uri);
    $wall = (microtime(true) - $started) * 1000;

    $dbTime = array_sum(array_column($log, 'ms'));
    $shapes = [];

    foreach ($log as $entry) {
        $shape = preg_replace('/\s+/', ' ', $entry['sql']);
        $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
    }

    arsort($shapes);
    printf("%-28s %7.0f ms  db %7.0f ms  %3d queries\n", $label, $wall, $dbTime, count($log));

    foreach (array_slice($shapes, 0, 2, true) as $shape => $count) {
        if ($count >= 10) {
            printf("    N+1? %d x %s\n", $count, mb_substr($shape, 0, 140));
        }
    }

    usort($log, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

    foreach (array_slice($log, 0, $top) as $entry) {
        if ($entry['ms'] < $slow || ! preg_match('/^\s*(select|with)\b/i', $entry['sql'])) {
            continue;
        }

        printf("    slow %.1f ms: %s\n", $entry['ms'], mb_substr(preg_replace('/\s+/', ' ', $entry['sql']), 0, 160));

        if (isset($options['plans'])) {
            foreach (DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$entry['sql'], $entry['bindings']) as $row) {
                echo '      '.((array) $row)['QUERY PLAN']."\n";
            }
        }
    }
}
