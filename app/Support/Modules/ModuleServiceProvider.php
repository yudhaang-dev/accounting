<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ReflectionClass;

/**
 * Base provider for a business module under app/Modules/<Module>.
 *
 * Loads the module's API routes (Http/routes.php, under /api/v1) and migrations
 * (Database/migrations) when they exist. See ADR-0002.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Table prefix owned by the module (e.g. "gl_"). */
    abstract public static function tablePrefix(): string;

    public function boot(): void
    {
        $path = $this->modulePath();

        if (is_file($routes = $path.'/Http/routes.php')) {
            Route::prefix('api/v1')->middleware('api')->group($routes);
        }

        if (is_dir($migrations = $path.'/Database/migrations')) {
            $this->loadMigrationsFrom($migrations);
        }
    }

    protected function modulePath(): string
    {
        $file = (new ReflectionClass($this))->getFileName();

        return dirname($file ?: throw new LogicException('Module provider must be defined in a file.'));
    }
}
