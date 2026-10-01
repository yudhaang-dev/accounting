<?php

use App\Support\Modules\ModuleServiceProvider;

$modules = [
    'MasterData' => 'md_',
    'IdentityAccess' => 'ia_',
    'TransactionEntry' => 'te_',
    'Subledger' => 'sl_',
    'GeneralLedger' => 'gl_',
    'Planning' => 'pl_',
    'Reporting' => 'rp_',
];

foreach ($modules as $module => $prefix) {
    it("registers the $module module provider with table prefix $prefix", function () use ($module, $prefix) {
        $provider = sprintf('App\\Modules\\%1$s\\%1$sServiceProvider', $module);

        expect(is_subclass_of($provider, ModuleServiceProvider::class))->toBeTrue()
            ->and($provider::tablePrefix())->toBe($prefix)
            ->and(app()->getProvider($provider))->not->toBeNull();
    });

    it("has the layer directories for $module", function () use ($module) {
        foreach (['Contracts', 'Domain', 'Application', 'Http', 'Database/migrations'] as $layer) {
            expect(is_dir(app_path("Modules/$module/$layer")))->toBeTrue();
        }
    });
}
