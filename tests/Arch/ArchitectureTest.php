<?php

/*
| Module boundary guards (ADR-0002). The dependency graph itself is enforced by deptrac.yaml;
| these tests cover what deptrac cannot: imports of other modules' internals, role-name checks
| and table prefixes.
*/

function ns(string ...$parts): string
{
    return implode(chr(92), ['App', ...$parts]);
}

const APP_DIR = __DIR__.'/../../app';

$modules = [
    'MasterData' => 'md_',
    'IdentityAccess' => 'ia_',
    'TransactionEntry' => 'te_',
    'Subledger' => 'sl_',
    'GeneralLedger' => 'gl_',
    'Planning' => 'pl_',
    'Reporting' => 'rp_',
];

foreach (array_keys($modules) as $module) {
    foreach (array_keys($modules) as $other) {
        if ($module === $other) {
            continue;
        }

        arch("$module only imports Contracts of $other")
            ->expect(ns('Modules', $module))
            ->not->toUse(ns('Modules', $other))
            ->ignoring(ns('Modules', $other, 'Contracts'));
    }

    arch("$module does not depend on Orchestration")
        ->expect(ns('Modules', $module))
        ->not->toUse(ns('Orchestration'));
}

arch('Platform and Support do not depend on business modules')
    ->expect([ns('Platform'), ns('Support')])
    ->not->toUse([ns('Modules'), ns('Orchestration')]);

/**
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    $files = [];

    if (! is_dir($directory)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('never checks role names in code (P-01)', function () {
    $forbidden = '/->(hasRole|hasAnyRole|hasAllRoles|hasExactRoles)\(|[\'"]role:|[\'"](role|role_or_permission)[\'"]\s*=>|[\'"](accounting_admin|preparer|approver|viewer|auditor)[\'"]/';

    $offenders = array_filter(
        phpFilesUnder(APP_DIR),
        fn (string $path) => preg_match($forbidden, file_get_contents($path)) === 1,
    );

    expect($offenders)->toBe([]);
});

it('uses only the owning module prefix for table names', function () use ($modules) {
    $prefixes = $modules + ['Platform' => 'pf_'];
    $violations = [];

    foreach ($prefixes as $module => $prefix) {
        $root = $module === 'Platform' ? APP_DIR.'/Platform' : APP_DIR."/Modules/$module";

        foreach (phpFilesUnder($root) as $path) {
            if (! str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/Database/migrations/')) {
                continue;
            }

            preg_match_all('/Schema::(?:create|table|drop|dropIfExists|rename)\(\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($path), $matches);

            foreach ($matches[1] as $table) {
                if (! str_starts_with($table, $prefix)) {
                    $violations[] = "$module: $table";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
