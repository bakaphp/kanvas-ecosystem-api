<?php

declare(strict_types=1);

namespace Kanvas\Companies\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\SetUsersCountAction;
use Kanvas\Companies\Models\Companies;
use Kanvas\KanvasModules\Actions\RefreshCompanyKanvasModulesSummaryAction;

class CompanyDashboardJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        public Companies $company,
        public Apps $app,
    ) {
    }

    public function handle(): void
    {
        new SetUsersCountAction($this->company)->execute();
        new RefreshCompanyKanvasModulesSummaryAction($this->company, $this->app)->execute();
    }
}
