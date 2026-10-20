<?php

namespace App\Modules\Reporting;

use App\Modules\Reporting\Application\PublishSnapshotsOnClose;
use App\Support\Ledger\ClosingListener;
use App\Support\Modules\ModuleServiceProvider;

class ReportingServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'rp_';
    }

    public function register(): void
    {
        $this->app->tag(PublishSnapshotsOnClose::class, ClosingListener::TAG);
    }
}
