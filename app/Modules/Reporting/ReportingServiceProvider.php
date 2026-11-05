<?php

namespace App\Modules\Reporting;

use App\Modules\Reporting\Application\BuildReportPackage;
use App\Modules\Reporting\Application\PublishSnapshotsOnClose;
use App\Modules\Reporting\Application\ReportTemplateComponent;
use App\Modules\Reporting\Contracts\ReportPackages;
use App\Support\Ledger\ClosingListener;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Template\TemplateComponent;

class ReportingServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'rp_';
    }

    public function register(): void
    {
        $this->app->bind(ReportPackages::class, BuildReportPackage::class);
        $this->app->tag(PublishSnapshotsOnClose::class, ClosingListener::TAG);
        $this->app->tag(ReportTemplateComponent::class, TemplateComponent::TAG);
    }
}
