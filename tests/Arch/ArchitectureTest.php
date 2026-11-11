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
    'Consolidation' => 'co_',
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

it('opens the hard-delete bypass only inside the retention platform capability', function () {
    $offenders = array_filter(
        phpFilesUnder(APP_DIR),
        fn (string $path) => str_contains(file_get_contents($path), 'app.retention_purge')
            && ! str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/Platform/Retention/'),
    );

    expect($offenders)->toBe([]);
});

it('has no literal passwords in application code, seeders, or end-to-end support', function () {
    $root = __DIR__.'/../..';
    $files = [
        ...phpFilesUnder(APP_DIR),
        ...phpFilesUnder($root.'/database/seeders'),
    ];

    foreach (['e2e', 'e2e/support', 'e2e/manual'] as $directory) {
        foreach (glob("$root/$directory/*.{ts,mjs,php}", GLOB_BRACE) as $path) {
            $files[] = $path;
        }
    }

    $literal = '/[\'"]password[\'"]\s*=>\s*[\'"](?!hashed[\'"])[^\'"]+[\'"]|Hash::make\(\s*[\'"]|const\s+PASSWORD\b|\b[pP]assword\s*:\s*[\'"`][^\'"`]+[\'"`]/';

    $offenders = array_filter(
        $files,
        fn (string $path) => preg_match($literal, file_get_contents($path)) === 1,
    );

    expect(array_map(fn (string $path) => basename($path), $offenders))->toBe([]);
});
