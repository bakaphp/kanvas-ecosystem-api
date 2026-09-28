<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Duplicates\Actions\CheckOrganizationDuplicateOnCreateAction;
use Kanvas\Guild\Organizations\Models\Organization;

class CheckOrganizationDuplicateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly int $organizationId,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $organization = Organization::query()->where('id', $this->organizationId)->first();
        if ($organization === null) {
            return;
        }

        new CheckOrganizationDuplicateOnCreateAction($organization)->execute();
    }
}
