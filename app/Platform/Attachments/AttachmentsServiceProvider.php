<?php

namespace App\Platform\Attachments;

use App\Support\Modules\ModuleServiceProvider;

class AttachmentsServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'pf_';
    }

    public function register(): void
    {
        $this->app->singleton(Attachments::class);
    }
}
