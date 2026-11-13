<?php

/*
| Concurrency checks of D3e, run as separate PHP processes against a real database (not inside Pest, whose
| single connection and wrapping transaction cannot race). Needs the manifest of the volume seeder.
|
|   php tests/Performance/concurrency.php same-accounts [--workers=8] [--each=50]
|   php tests/Performance/concurrency.php same-entry    [--workers=8] [--each=20]
|   php tests/Performance/concurrency.php close-period  [--workers=6] [--each=200]
|   php tests/Performance/concurrency.php all
|
| Exit code 0 when every invariant holds. A violation is reported with the numbers, never smoothed over.
*/

use App\Modules\GeneralLedger\Application\SetPeriodStatus;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Company\CompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$options = [];

foreach ($args as $i => $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $options[$key] = $value;
        unset($args[$i]);
    }
}

$args = array_values($args);
$manifestPath = storage_path('app/perf-manifest.json');
$manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : throw new RuntimeException('Run the volume seeder first (no manifest).');
$company = $manifest['companies'][0];

if (($args[0] ?? '') === 'worker') {
    exit(worker($args[1], (int) $args[2], (int) $options['each'], $company, $options));
}

$scenarios = ($args[0] ?? 'all') === 'all' ? ['same-accounts', 'same-entry', 'close-period'] : [$args[0]];
$failed = false;

foreach ($scenarios as $scenario) {
    $failed = ! run($scenario, $company, $options) || $failed;
}

exit($failed ? 1 : 0);

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, string>  $options
 */
function run(string $scenario, array $company, array $options): bool
{
    $workers = (int) ($options['workers'] ?? ($scenario === 'close-period' ? 6 : 8));
    $each = (int) ($options['each'] ?? match ($scenario) {
        'same-accounts' => 50,
        'same-entry' => 20,
        default => 200,
    });
    $periods = $company['periods'];
    $period = $periods[$scenario === 'close-period' ? 2 : 1];
    $before = DB::table('gl_journals')->where('company_id', $company['company_id'])->where('period_id', $period['id'])->count();
    $started = microtime(true);
    // New entry keys every run, so a second run does not find the entries of the first.
    $nonce = bin2hex(random_bytes(4));

    $processes = [];

    for ($w = 0; $w < $workers; $w++) {
        $command = [PHP_BINARY, __FILE__, 'worker', $scenario, (string) $w, "--each={$each}", "--period={$period['id']}", "--date={$period['start']}", "--nonce={$nonce}"];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null);
        stream_set_blocking($pipes[1], false);
        $processes[] = [$process, $pipes];
    }

    $closedAt = null;
    $countAtClose = null;
    $output = array_fill(0, $workers, '');

    if ($scenario === 'close-period') {
        // Close while the workers are posting: wait until some of their journals are committed (starting a process takes a while).
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline && DB::table('gl_journals')->where('company_id', $company['company_id'])->where('period_id', $period['id'])->count() < $before + 30) {
            usleep(20_000);
        }

        $closeStarted = microtime(true);
        app(CompanyContext::class)->run($company['company_id'], fn () => app(SetPeriodStatus::class)->close($period['id']));
        $closedAt = microtime(true) - $closeStarted;
        // What a reader sees right after the close committed; anything that appears later slipped past it.
        $countAtClose = DB::table('gl_journals')->where('company_id', $company['company_id'])->where('period_id', $period['id'])->count();
    }

    foreach ($processes as $w => [$process, $pipes]) {
        stream_set_blocking($pipes[1], true);
        $output[$w] = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        proc_close($process);
    }

    $elapsed = microtime(true) - $started;
    $results = ['ok' => 0, 'replayed' => 0, 'closed' => 0, 'errors' => []];

    foreach ($output as $text) {
        foreach (explode("\n", trim($text)) as $line) {
            $row = json_decode($line, true);

            if (! is_array($row)) {
                if (trim($line) !== '') {
                    $results['errors'][] = mb_substr($line, 0, 300);
                }

                continue;
            }

            $results[$row['r']] = ($results[$row['r']] ?? 0) + 1;

            if ($row['r'] === 'error') {
                $results['errors'][] = $row['m'];
            }
        }
    }

    $after = DB::table('gl_journals')->where('company_id', $company['company_id'])->where('period_id', $period['id'])->count();
    $problems = [];

    if ($scenario === 'same-accounts') {
        $expected = $workers * $each;

        if ($after - $before !== $expected) {
            $problems[] = 'journals written: '.($after - $before)." instead of {$expected}";
        }

        $problems = [...$problems, ...consistency($company['company_id'], $period['id'])];
        $numbers = DB::table('gl_journals')->where('company_id', $company['company_id'])->selectRaw('count(*) c, count(distinct number) d')->first();

        if ($numbers->c !== $numbers->d) {
            $problems[] = 'journal numbers are not unique';
        }
    }

    if ($scenario === 'same-entry') {
        if ($after - $before !== $each) {
            $problems[] = 'journals written: '.($after - $before)." instead of exactly {$each} (one per entry)";
        }

        $problems = [...$problems, ...consistency($company['company_id'], $period['id'])];
    }

    if ($scenario === 'close-period') {
        if ($after !== $countAtClose) {
            $problems[] = 'postings landed after the period was closed: '.($after - $countAtClose).' journals committed after the close returned';
        }

        app(CompanyContext::class)->run($company['company_id'], fn () => app(SetPeriodStatus::class)->reopen($period['id'], 'perf: concurrency check'));
    }

    $deadlocks = count(array_filter($results['errors'], fn (string $e): bool => str_contains($e, 'deadlock')));

    if ($deadlocks > 0) {
        $problems[] = "{$deadlocks} deadlocks";
    }

    if ($scenario !== 'close-period' && $results['errors'] !== []) {
        $problems[] = count($results['errors']).' failed postings, first: '.$results['errors'][0];
    }

    printf("%-14s workers=%d each=%d  ok=%d replayed=%d refused-closed=%d errors=%d  %.1fs%s\n", $scenario, $workers, $each, $results['ok'], $results['replayed'], $results['closed'], count($results['errors']), $elapsed, $closedAt !== null ? sprintf(' (close took %.2fs)', $closedAt) : '');

    foreach ($problems as $problem) {
        echo "  FAIL: {$problem}\n";
    }

    echo $problems === [] ? "  PASS\n" : '';

    return $problems === [];
}

/**
 * The balance rows of the period must equal the sum of its journal lines, and every journal must balance.
 *
 * @return list<string>
 */
function consistency(string $companyId, string $periodId): array
{
    $problems = [];
    $diff = DB::selectOne('SELECT count(*) c FROM (
        SELECT b.account_id, b.dimension_set_id FROM gl_account_balances b
        LEFT JOIN (SELECT account_id, dimension_set_id, sum(debit) d, sum(credit) c FROM gl_journal_lines WHERE company_id = ? AND period_id = ? GROUP BY 1, 2) l
          ON l.account_id = b.account_id AND l.dimension_set_id = b.dimension_set_id
        WHERE b.company_id = ? AND b.period_id = ? AND (coalesce(l.d, 0) <> b.debit OR coalesce(l.c, 0) <> b.credit)
    ) x', [$companyId, $periodId, $companyId, $periodId]);

    if ($diff->c > 0) {
        $problems[] = "{$diff->c} balance rows differ from the sum of their journal lines";
    }

    $unbalanced = DB::selectOne('SELECT count(*) c FROM (SELECT journal_id FROM gl_journal_lines WHERE company_id = ? AND period_id = ? GROUP BY 1 HAVING sum(debit) <> sum(credit)) x', [$companyId, $periodId]);

    if ($unbalanced->c > 0) {
        $problems[] = "{$unbalanced->c} unbalanced journals";
    }

    return $problems;
}

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, string>  $options
 */
function worker(string $scenario, int $index, int $each, array $company, array $options): int
{
    $posting = app(PostingService::class);
    $cash = $company['accounts']['cash'];
    $sales = $company['accounts']['sales'];
    $set = $company['dimension_values'] === [] ? null : DB::table('md_dimension_sets')->where('company_id', $company['company_id'])->orderBy('id')->value('id');

    for ($i = 0; $i < $each; $i++) {
        // `same-entry`: every worker posts the same entry keys; the others post their own.
        $entry = $scenario === 'same-entry' ? "perf-entry-{$options['nonce']}-{$i}" : null;

        try {
            $result = $posting->post(new PostJournal(
                companyId: $company['company_id'],
                accountingDate: $options['date'],
                description: "race {$scenario} {$index}.{$i}",
                // Cash accounts of the template take only the subledger as a source.
                source: JournalSource::Subledger,
                lines: [new PostJournalLine($cash, '1000', '0', dimensionSetId: $set), new PostJournalLine($sales, '0', '1000', dimensionSetId: $set)],
                entryId: $entry,
            ));
            echo json_encode(['r' => $result->replayed ? 'replayed' : 'ok'])."\n";
        } catch (Throwable $e) {
            $closed = $e instanceof ApiException && $e->errorCode === ErrorCode::PeriodNotOpen;
            echo json_encode($closed ? ['r' => 'closed'] : ['r' => 'error', 'm' => mb_substr($e->getMessage(), 0, 300)])."\n";
        }
    }

    return 0;
}
