<?php

declare(strict_types=1);

namespace Kanvas\Companies\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Companies\Actions\SetUsersCountAction;

class CompanyDashboardJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app
    ) {
    }

    public function handle(): void
    {
        (new SetUsersCountAction($this->company))->execute();
    }
}
