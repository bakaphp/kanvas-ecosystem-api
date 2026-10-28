<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Research\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Knowledge\Research\Actions\ResearchCompanyWebsiteAction;
use Kanvas\Users\Models\Users;

final class ResearchCompanyWebsiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
        public readonly string $website,
    ) {
        $this->onQueue('onboarding');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new ResearchCompanyWebsiteAction(
            $this->app,
            $this->company,
            $this->user,
            $this->website,
        )->execute();
    }
}
